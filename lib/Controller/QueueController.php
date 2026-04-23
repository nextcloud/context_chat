<?php


declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Controller;

use OCA\ContextChat\BackgroundJobs\StorageCrawlJob;
use OCA\ContextChat\Db\QueueActionMapper;
use OCA\ContextChat\Db\QueueContentItem;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Db\QueueMapper;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\QueueService;
use OCA\ContextChat\Service\StorageService;
use OCA\ContextChat\Type\Source;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\ExAppRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\OCSController;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class QueueController extends OCSController {
	private const INDEX_COMPLETION_THRESHOLD = 0.02; // 2%

	public function __construct(
		string $appName,
		IRequest $request,
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private QueueService $queueService,
		private StorageService $storageService,
		private IJobList $jobList,
		private ITimeFactory $timeFactory,
		private QueueMapper $queueMapper,
		string $corsMethods = 'PUT, POST, GET, DELETE, PATCH',
		string $corsAllowedHeaders = 'Authorization, Content-Type, Accept, OCS-APIRequest',
		int $corsMaxAge = 1728000,
	) {
		parent::__construct($appName, $request, $corsMethods, $corsAllowedHeaders, $corsMaxAge);
	}

	/**
	 * ExApp-only endpoint to retrieve file contents by fileId
	 * @param IRootFolder $rootFolder
	 * @param int $fileId
	 * @param string $userId
	 * @return DataResponse|StreamResponse
	 */
	#[ExAppRequired]
	#[ApiRoute(verb: 'GET', url: '/files/{fileId}')]
	public function getFileContents(IRootFolder $rootFolder, int $fileId, string $userId) : DataResponse|Http\StreamResponse {
		try {
			$file = $rootFolder->getUserFolder($userId)->getFirstNodeById($fileId);
			if (!$file || !$file instanceof \OCP\Files\File) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			$stream = $file->fopen('r');
			if (!$stream) {
				return new DataResponse([], Http::STATUS_NOT_FOUND);
			}

			return new Http\StreamResponse($stream);
		} catch (\Throwable $e) {
			// Avoid leaking filesystem details; keep behavior consistent with other failure paths.
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
	}

	/**
	 * ExApp-only endpoint to retrieve items from documents queues
	 * @param QueueMapper $queueMapper
	 * @param QueueContentItemMapper $queueContentItemMapper
	 * @param int $n
	 * @return DataResponse
	 */
	#[ExAppRequired]
	#[ApiRoute(verb: 'GET', url: '/queues/documents/')]
	public function getDocumentsQueueItems(
		StorageService $storageService,
		IRootFolder $rootFolder,
		QueueMapper $queueMapper,
		QueueContentItemMapper $queueContentItemMapper,
		IUserMountCache $userMountCache,
		int $n = 64,
	) : DataResponse {
		if ($n <= 0) {
			return new DataResponse(['message' => 'Parameter n must be a positive integer'], Http::STATUS_BAD_REQUEST);
		}

		$maxN = 1024;
		if ($n > $maxN) {
			$n = $maxN;
		}
		try {
			$files = [];
			while (count($files) < $n) {
				$limit = $n - count($files);
				$documents = $queueMapper->getFromQueue($limit);
				if (empty($documents)) {
					break;
				}
				foreach ($documents as $document) {
					if ($queueMapper->lock($document->getId())) {
						try {
							$files[$document->getId()] = $this->getFileSource($document, $rootFolder, $storageService, $userMountCache);
						} catch (\Exception $e) {
							$this->logger->warning($e->getMessage(), ['exception' => $e]);
							$queueMapper->delete($document);
						}
					}
				}
			}
			$contentItems = [];
			while (count($contentItems) < $n) {
				$limit = $n - count($contentItems);
				$documents = $queueContentItemMapper->getFromQueue($limit);
				if (empty($documents)) {
					break;
				}
				foreach ($documents as $document) {
					if ($queueContentItemMapper->lock($document->getId())) {
						$contentItems[$document->getId()] = $this->getContentItemSource($document);
					}
				}
			}
			return new DataResponse([
				'files' => (object)$files,
				'content_providers' => (object)$contentItems,
			]);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * ExApp-only endpoint for backend to remove items from documents queues
	 * @param IDBConnection $db
	 * @param QueueMapper $queueMapper
	 * @param QueueContentItemMapper $queueContentItemMapper
	 * @param list<int> $files
	 * @param list<int> $content_providers
	 * @return DataResponse
	 */
	#[ExAppRequired]
	#[ApiRoute(verb: 'DELETE', url: '/queues/documents/')]
	public function deleteDocumentsQueueItems(IDBConnection $db, QueueMapper $queueMapper, QueueContentItemMapper $queueContentItemMapper, array $files, array $content_providers) : DataResponse {
		try {
			$db->beginTransaction();
			$queueMapper->removeFromQueue($files);
			$queueContentItemMapper->removeFromQueue($content_providers);
			$db->commit();
		} catch (Exception $e) {
			try {
				$db->rollBack();
			} catch (Exception $e) {
				$this->logger->error($e->getMessage(), ['exception' => $e]);
			}
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		try {
			$this->setInitialIndexCompletion();
		} catch (\Exception $e) {
			$this->logger->warning('Could not check for initial index completion', ['exception' => $e]);
		}

		return new DataResponse();
	}

	/**
	 * Admin-only Stats endpoint for external auto-scalers
	 * @return DataResponse
	 */
	#[ApiRoute(verb: 'GET', url: '/queues/documents/stats')]
	#[Http\Attribute\NoCSRFRequired]
	public function countDocumentsQueueItems(QueueMapper $queueMapper, QueueContentItemMapper $contentItemMapper) : DataResponse {
		try {
			$count = $queueMapper->count();
			foreach ($contentItemMapper->count() as $providerCount) {
				$count += $providerCount;
			}
			$locked = $queueMapper->countLocked();
			foreach ($contentItemMapper->countLocked() as $providerCount) {
				$locked += $providerCount;
			}
			return new DataResponse([ 'scheduled' => $count, 'running' => $locked ]);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * ExApp-only endpoint for backend to get actions from queue
	 * @param QueueActionMapper $queueActionMapper
	 * @param int $n
	 * @return DataResponse
	 */
	#[ExAppRequired]
	#[ApiRoute(verb: 'GET', url: '/queues/actions/')]
	public function getActionsQueueItems(QueueActionMapper $queueActionMapper, int $n = 512) : DataResponse {
		try {
			$actions = [];
			while (count($actions) < $n) {
				$limit = $n - count($actions);
				$documents = $queueActionMapper->getFromQueue($limit);
				if (empty($documents)) {
					break;
				}
				foreach ($documents as $document) {
					if ($queueActionMapper->lock($document->getId())) {
						$actions[$document->getId()] = $document;
					}
				}
			}
			return new DataResponse(['actions' => (object)$actions]);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * ExApp-only endpoint for backend to remove items from actions queue
	 * @param IDBConnection $db
	 * @param QueueActionMapper $queueActionMapper
	 * @param list<int> $actions
	 * @return DataResponse
	 */
	#[ExAppRequired]
	#[ApiRoute(verb: 'DELETE', url: '/queues/actions/')]
	public function deleteActionsQueueItems(IDBConnection $db, QueueActionMapper $queueActionMapper, array $actions) : DataResponse {
		try {
			$db->beginTransaction();
			$queueActionMapper->removeFromQueue($actions);
			$db->commit();
			return new DataResponse();
		} catch (Exception $e) {
			try {
				$db->rollBack();
			} catch (Exception $e) {
				$this->logger->error($e->getMessage(), ['exception' => $e]);
			}
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Admin-only Stats endpoint for external auto-scalers
	 * @return DataResponse
	 */
	#[ApiRoute(verb: 'GET', url: '/queues/actions/stats')]
	#[Http\Attribute\NoCSRFRequired]
	public function countActionsQueueItems(QueueActionMapper $queueActionMapper) : DataResponse {
		try {
			$count = $queueActionMapper->count();
			$locked = $queueActionMapper->countLocked();
			return new DataResponse([ 'scheduled' => $count, 'running' => $locked ]);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	private function getFileSource(QueueFile $document, IRootFolder $rootFolder, StorageService $storageService, IUserMountCache $userMountCache) : Source {
		$mounts = $userMountCache->getMountsForStorageId($document->getStorageId());
		if (empty($mounts)) {
			throw new \Exception('Couldn\'t find any mounts for this storage');
		}
		$userId = $mounts[0]->getUser()->getUID();

		try {
			$file = $rootFolder->getUserFolder($userId)->getFirstNodeById($document->getFileId());
		} catch (NotPermittedException $e) {
			throw new \Exception('Not allowed to get user folder');
		}
		if (!($file instanceof File)) {
			throw new \Exception('File not found or not a file');
		}
		$userIds = $storageService->getUsersForFileId($document->getFileId());

		return new Source(
			$userIds,
			ProviderConfigService::getSourceId($file->getId()),
			$file->getInternalPath() ?: $file->getPath() ?: $file->getName(),
			null,
			$file->getMTime(),
			$file->getMimeType(),
			ProviderConfigService::getDefaultProviderKey(),
			$file->getSize()
		);
	}

	private function getContentItemSource(QueueContentItem $document) : Source {
		$providerKey = ProviderConfigService::getConfigKey($document->getAppId(), $document->getProviderId());
		return new Source(
			explode(',', $document->getUsers()),
			ProviderConfigService::getSourceId($document->getItemId(), $providerKey),
			$document->getTitle(),
			$document->getContent(),
			$document->getLastModified()->getTimestamp(),
			$document->getDocumentType(),
			$providerKey,
			strlen($document->getContent()),
		);
	}

	/**
	 * @template T of \OCP\BackgroundJob\Job
	 * @psalm-param T::class $jobClass
	 */
	public function getJobCount(string $jobClass): int {
		$countByClass = array_values(array_filter($this->jobList->countByClass(), fn ($row) => $row['class'] == $jobClass));
		$jobCount = count($countByClass) > 0 ? $countByClass[0]['count'] : 0;
		return $jobCount;
	}

	private function setInitialIndexCompletion(): void {
		if ($this->appConfig->getAppValueInt('last_indexed_time', 0, lazy: true) !== 0) {
			return;
		}

		try {
			$crawlJobCount = $this->getJobCount(StorageCrawlJob::class);
			if ($crawlJobCount > 0) {
				$this->logger->debug('StorageCrawlJob\'s still scheduled for execution, intial indexing has not completed.');
				return;
			}
		} catch (\Exception $e) {
			$this->logger->warning('Could not get count of scheduled StorageCrawlJob jobs', ['exception' => $e]);
			return;
		}

		try {
			$lastEnqueuedDbId = $this->appConfig->getAppValueInt('last_enqueued_db_id', -1, lazy: true);
			if ($lastEnqueuedDbId !== -1) {
				$initiallyQueuedFilesExist = $this->queueMapper->existsQueueItemsUpToDbId($lastEnqueuedDbId);
				if ($initiallyQueuedFilesExist) {
					$this->logger->debug('Initially queued files still in the queue, intial indexing has not completed.');
					return;
				}
				$this->logger->info('Initial index completion detected, setting last indexed time');
				$this->appConfig->setAppValueInt('last_indexed_time', $this->timeFactory->getTime(), lazy: true);
				return;
			}
		} catch (\Exception $e) {
			$this->logger->warning('Could not get last enqueued file\'s DB id', ['exception' => $e]);
		}

		// last enqueued file's ID could not be retrieved, falling back to file counting method
		try {
			$queuedNewFilesCount = $this->queueService->countNewFiles();
			$eligibleFilesCount = $this->storageService->countFiles();
			// if the new files in the queue are less than 2% of the total eligible files, we consider the
			// initial indexing complete this allows for some margin of error in case some files were
			// added while we were indexing but still ensures that we have indexed the vast majority of
			// files at least once
			if (self::withinThreshold($queuedNewFilesCount, $eligibleFilesCount)) {
				$this->logger->info('Initial index completion detected, setting last indexed time');
				$this->appConfig->setAppValueInt('last_indexed_time', $this->timeFactory->getTime(), lazy: true);
				return;
			}
		} catch (\OCP\DB\Exception $e) {
			$this->logger->warning('Could not count queued new files or total eligible files', ['exception' => $e]);
			return;
		}

		// we are still indexing files that were never indexed before.
		$this->logger->debug('Initial indexing not completed yet', [
			'queuedNewFilesCount' => $queuedNewFilesCount,
			'eligibleFilesCount' => $eligibleFilesCount,
		]);
	}

	private static function withinThreshold(int $current, int $total, float $threshold = self::INDEX_COMPLETION_THRESHOLD): bool {
		return ((float)($total - $current) / (float)$total) < $threshold;
	}
}
