<?php

/*
 * Copyright (c) 2022 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Service\DiagnosticService;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\QueueService;
use OCA\ContextChat\Service\StorageService;
use OCA\ContextChat\Type\Source;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use OCP\DB\Exception;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class IndexerJob extends TimedJob {

	public const DEFAULT_MAX_INDEXING_TIME = 30 * 60;

	public function __construct(
		ITimeFactory $time,
		private LoggerInterface $logger,
		private QueueService $queue,
		private IUserMountCache $userMountCache,
		private IJobList $jobList,
		private LangRopeService $langRopeService,
		private StorageService $storageService,
		private IRootFolder $rootFolder,
		private IAppConfig $appConfig,
		private DiagnosticService $diagnosticService,
	) {
		parent::__construct($time);
		$this->setInterval($this->getMaxIndexingTime());
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
	}

	/**
	 * @param array{storageId: int, rootId: int} $argument
	 * @return void
	 * @throws Exception
	 * @throws \ErrorException
	 * @throws \Throwable
	 */
	public function run($argument): void {
		/**
		 * @var int $storageId
		 */
		$storageId = $argument['storageId'];
		$rootId = $argument['rootId'];
		if ($this->appConfig->getAppValue('auto_indexing', 'true') === 'false') {
			return;
		}
		$this->logger->debug('Index files of storage ' . $storageId);
		try {
			$this->logger->debug('fetching ' . $this->getBatchSize() . ' files from queue');
			$files = $this->queue->getFromQueue($storageId, $rootId, $this->getBatchSize());
		} catch (Exception $e) {
			$this->logger->error('Cannot retrieve items from  queue', ['exception' => $e]);
			return;
		}

		$this->diagnosticService->sendHeartbeat(static::class, $this->getId());

		// Setup Filesystem for a users that can access this mount
		$mounts = array_values(array_filter($this->userMountCache->getMountsForStorageId($storageId), function (ICachedMountInfo $mount) use ($rootId) {
			return $mount->getRootId() === $rootId;
		}));

		if (count($mounts) > 0) {
			\OC_Util::setupFS($mounts[0]->getUser()->getUID());
		}

		try {
			$this->logger->debug('Running indexing');
			$this->index($files);
		} catch (\RuntimeException $e) {
			$this->logger->warning('Temporary problem with indexing, trying again soon', ['exception' => $e]);
		} catch (\ErrorException $e) {
			$this->logger->warning('Problem with indexing', ['exception' => $e]);
			$this->logger->debug('Removing ' . static::class . ' with argument ' . var_export($argument, true) . 'from oc_jobs');
			$this->jobList->remove(static::class, $argument);
			throw $e;
		}

		try {
			// If there is at least one file left in the queue, reschedule this job
			$files = $this->queue->getFromQueue($storageId, $rootId, 1);
			if (count($files) === 0) {
				$this->logger->debug('Removing ' . static::class . ' with argument ' . var_export($argument, true) . 'from oc_jobs');
				$this->jobList->remove(static::class, $argument);
			}
		} catch (Exception $e) {
			$this->logger->error('Cannot retrieve items from queue', ['exception' => $e]);
			return;
		}
	}

	/**
	 * @return int
	 */
	protected function getBatchSize(): int {
		return $this->appConfig->getAppValueInt('indexing_batch_size', 100);
	}

	protected function getMaxIndexingTime(): int {
		return $this->appConfig->getAppValueInt('indexing_max_time', self::DEFAULT_MAX_INDEXING_TIME);
	}

	/**
	 * @param QueueFile[] $files
	 * @return void
	 * @throws \RuntimeException|\ErrorException
	 */
	protected function index(array $files): void {
		$maxTime = $this->getMaxIndexingTime();
		$startTime = time();
		$sources = [];
		$allSourceIds = [];
		$loadedSources = [];
		$size = 0;

		foreach ($files as $queueFile) {
			$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
			if ($startTime + $maxTime < time()) {
				break;
			}

			$file = current($this->rootFolder->getById($queueFile->getFileId()));
			if (!$file instanceof File) {
				continue;
			}

			$file_size = $file->getSize();
			if ($size + $file_size > Application::CC_MAX_SIZE || count($sources) >= Application::CC_MAX_FILES) {
				$loadedSources = array_merge($loadedSources, $this->langRopeService->indexSources($sources));
				$sources = [];
				$size = 0;
			}

			$userIds = $this->storageService->getUsersForFileId($queueFile->getFileId());
			$this->diagnosticService->sendHeartbeat(static::class, $this->getId());

			try {
				try {
					$fileHandle = $file->fopen('r');
				} catch (LockedException|NotPermittedException $e) {
					$this->logger->error('Could not open file ' . $file->getPath() . ' for reading', ['exception' => $e]);
					continue;
				}
				if (!is_resource($fileHandle)) {
					$this->logger->warning('File handle for' . $file->getPath() . ' is not readable');
					continue;
				}

				$sources[] = new Source(
					$userIds,
					ProviderConfigService::getSourceId($file->getId()),
					substr($file->getInternalPath(), 6), // remove 'files/' prefix
					$fileHandle,
					$file->getMtime(),
					$file->getMimeType(),
					ProviderConfigService::getDefaultProviderKey(),
				);
				$allSourceIds[] = ProviderConfigService::getSourceId($file->getId());
			} catch (InvalidPathException|NotFoundException $e) {
				$this->logger->error('Could not find file ' . $file->getPath(), ['exception' => $e]);
				continue;
			}
		}

		if (count($sources) > 0) {
			$loadedSources = array_merge($loadedSources, $this->langRopeService->indexSources($sources));
		}

		$emptyInvalidSources = array_diff($allSourceIds, $loadedSources);
		if (count($emptyInvalidSources) > 0) {
			$this->logger->info('Invalid or empty sources that were not indexed', ['sourceIds' => $emptyInvalidSources]);
		}

		try {
			$this->queue->removeFromQueue($files);
		} catch (Exception $e) {
			$this->logger->error('Could not remove indexed files from queue', ['exception' => $e]);
		}
	}
}
