<?php


declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Controller;

use OCA\ContextChat\Db\QueueActionMapper;
use OCA\ContextChat\Db\QueueContentItem;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Db\QueueMapper;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\StorageService;
use OCA\ContextChat\Type\Source;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\ExAppRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\StreamResponse;
use OCP\AppFramework\OCSController;
use OCP\DB\Exception;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class QueueController extends OCSController {
	public function __construct(
		$appName,
		IRequest $request,
		$corsMethods = 'PUT, POST, GET, DELETE, PATCH',
		$corsAllowedHeaders = 'Authorization, Content-Type, Accept, OCS-APIRequest',
		$corsMaxAge = 1728000,
		private LoggerInterface $logger,
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
						$files[$document->getId()] = $this->getFileSource($document, $rootFolder, $storageService);
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

	private function getFileSource(QueueFile $document, IRootFolder $rootFolder, StorageService $storageService) : Source {
		$file = $rootFolder->getFirstNodeById($document->getFileId());
		if ($file === null || !($file instanceof File)) {
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
}
