<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 The Recognize contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Exceptions\RetryIndexException;
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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IDBConnection;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Indexer Job
 * Makes use of the following app config settings:
 *
 * auto_indexing: bool = true The job only runs if this is true
 * indexing_batch_size: int = 100 The number of files to index per run
 * indexing_max_time: int = 30*60 The number of seconds to index files for per run, regardless of batch size
 * indexing_max_jobs_count: int = 3 The maximum number of Indexer jobs allowed to run at the same time
 */
class IndexerJob extends TimedJob {

	public const DEFAULT_MAX_INDEXING_TIME = 30 * 60;
	public const DEFAULT_MAX_JOBS_COUNT = 10;

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
		private IDBConnection $db,
		private ITimeFactory $timeFactory,
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
		$this->setInitialIndexCompletion();
		if ($this->hasEnoughRunningJobs()) {
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

	protected function hasEnoughRunningJobs(): bool {
		if (!$this->jobList->hasReservedJob(static::class)) {
			// short circuit to false if no jobs are running, yet
			return false;
		}
		$count = 0;
		foreach ($this->jobList->getJobsIterator(static::class, null, 0) as $job) {
			// Check if job is running
			$query = $this->db->getQueryBuilder();
			$query->select('*')
				->from('jobs')
				->where($query->expr()->gt('reserved_at', $query->createNamedParameter($this->timeFactory->getTime() - $this->getMaxIndexingTime(), IQueryBuilder::PARAM_INT)))
				->where($query->expr()->gte('last_run', 'reserved_at')
				->andWhere($query->expr()->eq('id', $query->createNamedParameter($job->getId(), IQueryBuilder::PARAM_INT)))
				->setMaxResults(1);

			try {
				$result = $query->executeQuery();
				if ($result->fetch() !== false) {
					// count if it's running
					$count++;
				}
				$result->closeCursor();
			} catch (Exception $e) {
				$this->logger->warning('Querying reserved jobs failed', ['exception' => $e]);
			}
		}
		$maxCount = $this->appConfig->getAppValueInt('indexing_max_jobs_count', self::DEFAULT_MAX_JOBS_COUNT);
		// Either there are already less than the maximum, or we roll the dice according to the proportion of allowed jobs vs currently running ones
		// e.g. assume 8 jobs are allowed, currently there are 10 running, then we roll the dice and want to be lower than 0.8
		return $count <= $maxCount || $maxCount / $count > rand(0, 10000) / 10000);
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
		$retryQFiles = [];
		// work along with $sources to keep track of the $queueFile's that are being indexed
		$trackedQFiles = [];
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
				try {
					$loadedSources = array_merge($loadedSources, $this->langRopeService->indexSources($sources));
					$sources = [];
					$trackedQFiles = [];
					$size = 0;
				} catch (RetryIndexException $e) {
					$this->logger->debug('At least one source is already being processed from another request, trying again soon', ['exception' => $e]);
					$retryQFiles = array_merge($retryQFiles, $trackedQFiles);
					$sources = [];
					$trackedQFiles = [];
					$size = 0;
					continue;
				}
			}

			$userIds = $this->storageService->getUsersForFileId($queueFile->getFileId());
			$this->diagnosticService->sendHeartbeat(static::class, $this->getId());

			try {
				try {
					$fileHandle = $file->fopen('r');
				} catch (NotPermittedException $e) {
					$this->logger->error('Could not open file ' . $file->getPath() . ' for reading', ['exception' => $e]);
					continue;
				} catch (LockedException $e) {
					$retryQFiles[] = $queueFile;
					$this->logger->info('File ' . $file->getPath() . ' is locked, could not read for indexing. Adding it to the next batch.');
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
				$trackedQFiles[] = $queueFile;
				$allSourceIds[] = ProviderConfigService::getSourceId($file->getId());
			} catch (InvalidPathException|NotFoundException $e) {
				$this->logger->error('Could not find file ' . $file->getPath(), ['exception' => $e]);
				continue;
			}
		}

		if (count($sources) > 0) {
			try {
				$loadedSources = array_merge($loadedSources, $this->langRopeService->indexSources($sources));
			} catch (RetryIndexException $e) {
				$this->logger->debug('At least one source is already being processed from another request, trying again soon', ['exception' => $e]);
				return;
			}
		}

		$emptyInvalidSources = array_diff($allSourceIds, $loadedSources);
		if (count($emptyInvalidSources) > 0) {
			$this->logger->info('Invalid or empty sources that were not indexed', ['sourceIds' => $emptyInvalidSources]);
		}

		try {
			$this->queue->removeFromQueue($files);
			// add files that were locked to the end of the queue
			foreach ($retryQFiles as $queueFile) {
				$this->queue->insertIntoQueue($queueFile);
			}
		} catch (Exception $e) {
			$this->logger->error('Could not remove indexed files from queue', ['exception' => $e]);
		}
	}

	private function setInitialIndexCompletion(): void {
		// if  last indexed time is already set, we don't need to do anything
		if ($this->appConfig->getAppValueInt('last_indexed_time', 0, false) !== 0) {
			return;
		}

		// if any storage crawler jobs are running, we don't need to do anything
		if ($this->jobList->hasReservedJob(StorageCrawlJob::class)) {
			return;
		}

		// if the last indexed file id is in the queue, we don't need to do anything
		$lastIndexedFileId = $this->appConfig->getAppValueInt('last_indexed_file_id', 0, false);
		if ($lastIndexedFileId === 0) {
			return;
		}
		if ($this->queue->existsQueueFileId($lastIndexedFileId)) {
			return;
		}

		$this->logger->info('Initial index completion detected, setting last indexed time');
		$this->appConfig->setAppValueInt('last_indexed_time', $this->timeFactory->getTime(), false);
	}
}
