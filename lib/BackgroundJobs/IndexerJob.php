<?php
/*
 * Copyright (c) 2022 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);

namespace OCA\ContextChat\BackgroundJobs;

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
	public const DEFAULT_MAX_JOBS_COUNT = 3;

	public function __construct(
		ITimeFactory            $time,
		private LoggerInterface $logger,
		private QueueService    $queue,
		private IUserMountCache $userMountCache,
		private IJobList        $jobList,
		private LangRopeService $langRopeService,
		private StorageService  $storageService,
		private IRootFolder     $rootFolder,
		private IAppConfig     	$appConfig,
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
		// Sleep a bit randomly to avoid a scenario where all jobs are started at the same time and kill themselves directly
		sleep(rand(1, 3 * 60));
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
				->where($query->expr()->gt('reserved_at', $query->createNamedParameter($this->timeFactory->getTime() - 6 * 3600, IQueryBuilder::PARAM_INT)))
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
		return $count >= $this->appConfig->getAppValueInt('indexing_max_jobs_count', self::DEFAULT_MAX_JOBS_COUNT);
	}

	/**
	 * @param QueueFile[] $files
	 * @return void
	 * @throws \RuntimeException|\ErrorException
	 */
	protected function index(array $files): void {
		$maxTime = $this->getMaxIndexingTime();
		$startTime = time();
		foreach ($files as $queueFile) {
			$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
			if ($startTime + $maxTime < time()) {
				break;
			}
			$file = current($this->rootFolder->getById($queueFile->getFileId()));
			if (!$file instanceof File) {
				continue;
			}
			$userIds = $this->storageService->getUsersForFileId($queueFile->getFileId());
			foreach ($userIds as $userId) {
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
					$source = new Source(
						$userId,
						ProviderConfigService::getSourceId($file->getId()),
						$file->getPath(),
						$fileHandle,
						$file->getMtime(),
						$file->getMimeType(),
						ProviderConfigService::getDefaultProviderKey(),
					);
				} catch (InvalidPathException|NotFoundException $e) {
					$this->logger->error('Could not find file ' . $file->getPath(), ['exception' => $e]);
					continue 2;
				}
				$this->langRopeService->indexSources([$source]);
			}
			try {
				$this->queue->removeFromQueue($queueFile);
			} catch (Exception $e) {
				$this->logger->error('Could not remove file from queue', ['exception' => $e]);
			}
		}
	}
}
