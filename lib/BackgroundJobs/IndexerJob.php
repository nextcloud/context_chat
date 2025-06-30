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
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\DiagnosticService;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\QueueService;
use OCA\ContextChat\Service\StorageService;
use OCA\ContextChat\Type\Source;
use OCP\App\IAppManager;
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

/**
 * Indexer Job
 * Makes use of the following app config settings:
 *
 * auto_indexing: bool = true The job only runs if this is true
 * indexing_batch_size: int  The number of files to index per run
 * indexing_max_size: int The maximum size of a file to index in bytes, also the maximum size of a batch
 * indexing_job_interval: int The interval at which the indexer jobs run
 * indexing_max_time: int The number of seconds to index files for per run, regardless of batch size
 */
class IndexerJob extends TimedJob {

	public const DEFAULT_JOB_INTERVAL = 30 * 60;
	public const DEFAULT_MAX_INDEXING_TIME = 30 * 60;

	// Assuming a backend capacity of 50 files per minute we can send 1500 files in half an hour
	// Specifying a higher number here will still be overruled by the max indexing time
	public const DEFAULT_BATCH_SIZE = 5000;

	public int $storageId;
	public int $rootId;

	public function __construct(
		ITimeFactory $time,
		private QueueService $queue,
		private IUserMountCache $userMountCache,
		private IJobList $jobList,
		private LangRopeService $langRopeService,
		private StorageService $storageService,
		private IRootFolder $rootFolder,
		private IAppConfig $appConfig,
		private DiagnosticService $diagnosticService,
		private ITimeFactory $timeFactory,
		private IAppManager $appManager,
		private Logger $logger,
	) {
		parent::__construct($time);
		$this->setInterval($this->getJobInterval());
		$this->setAllowParallelRuns(false);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	/**
	 * @param array{storageId: int, rootId: int} $argument
	 * @return void
	 * @throws Exception
	 * @throws \ErrorException
	 * @throws \Throwable
	 */
	public function run($argument): void {
		if (!$this->appManager->isInstalled('app_api')) {
			$this->logger->warning('IndexerJob is skipped as app_api is disabled');
			return;
		}

		$this->storageId = $argument['storageId'];
		$this->rootId = $argument['rootId'];
		if ($this->appConfig->getAppValue('auto_indexing', 'true') === 'false') {
			return;
		}
		$this->diagnosticService->sendJobTrigger(static::class, $this->getId());
		$this->setInitialIndexCompletion();
		try {
			$files = $this->queue->getFromQueue($this->storageId, $this->rootId, $this->getBatchSize());
		} catch (Exception $e) {
			$this->logger->error('[IndexerJob] Cannot retrieve items from  queue', ['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
			return;
		}

		$this->diagnosticService->sendJobStart(static::class, $this->getId());
		$this->diagnosticService->sendHeartbeat(static::class, $this->getId());

		// Setup Filesystem for a users that can access this mount
		$mounts = array_values(array_filter($this->userMountCache->getMountsForStorageId($this->storageId), function (ICachedMountInfo $mount) {
			return $mount->getRootId() === $this->rootId;
		}));

		if (count($mounts) > 0) {
			\OC_Util::setupFS($mounts[0]->getUser()->getUID());
		}

		try {
			$this->logger->debug('[IndexerJob] Running indexing', ['storageId' => $this->storageId, 'rootId' => $this->rootId]);
			$this->index($files);
		} catch (\RuntimeException $e) {
			$this->logger->warning('[IndexerJob] Temporary problem with indexing', ['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
		} catch (\ErrorException $e) {
			$this->logger->warning('[IndexerJob]  Problem with indexing', ['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
			$this->logger->info('[IndexerJob] Removing ' . static::class . ' with argument ' . var_export($argument, true) . 'from oc_jobs');
			$this->jobList->remove(static::class, $argument);
			throw $e;
		}

		try {
			// If there is at least one file left in the queue, reschedule this job
			$files = $this->queue->getFromQueue($this->storageId, $this->rootId, 1);
			$indexerJobCount = $this->getJobCount(IndexerJob::class);
			$crawlJobCount = $this->getJobCount(StorageCrawlJob::class);
			if (count($files) === 0 && ($indexerJobCount > 1 || $crawlJobCount === 0)) {
				$this->logger->info('[IndexerJob]  Removing ' . static::class . ' with argument ' . var_export($argument, true) . 'from oc_jobs');
				$this->jobList->remove(static::class, $argument);
				$this->setInitialIndexCompletion();
			} elseif (count($files) === 0) {
				$this->logger->debug('[IndexerJob] No files left in queue, but we keep the job around to wait for potential StorageCrawlJob instances to finish');

			}
		} catch (Exception $e) {
			$this->logger->error('[IndexerJob] Cannot retrieve items from queue', ['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
			return;
		}
		$this->diagnosticService->sendJobEnd(static::class, $this->getId());
	}

	protected function getBatchSize(): int {
		return $this->appConfig->getAppValueInt('indexing_batch_size', self::DEFAULT_BATCH_SIZE);
	}

	protected function getMaxIndexingTime(): int {
		return $this->appConfig->getAppValueInt('indexing_max_time', self::DEFAULT_MAX_INDEXING_TIME);
	}

	protected function getJobInterval(): int {
		return $this->appConfig->getAppValueInt('indexing_job_interval', self::DEFAULT_JOB_INTERVAL);
	}

	protected function getMaxSize(): float {
		return (float)$this->appConfig->getAppValueInt('indexing_max_size', Application::CC_MAX_SIZE);
	}

	/**
	 * @param QueueFile[] $files
	 * @return void
	 * @throws \RuntimeException|\ErrorException
	 */
	protected function index(array $files): void {
		$maxTime = $this->getMaxIndexingTime();
		$maxSize = $this->getMaxSize();
		$startTime = time();
		$sources = [];
		$allSourceIds = [];
		$loadedSources = [];
		$sourcesToRetry = [];
		$retryQFiles = [];
		// work along with $sources to keep track of the $queueFile's that are being indexed
		$trackedQFiles = [];
		$size = 0.0;

		foreach ($files as $i => $queueFile) {
			$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
			if ($startTime + $maxTime < time()) {
				break;
			}

			$file = current($this->rootFolder->getById($queueFile->getFileId()));
			if (!$file instanceof File) {
				continue;
			}

			try {
				$fileSize = (float)$file->getSize();

				if ($fileSize > $maxSize) {
					$this->logger->info('[IndexerJob] File is too large to index', [
						'size' => $fileSize,
						'maxSize' => $maxSize,
						'nodeId' => $file->getId(),
						'path' => $file->getPath(),
						'storageId' => $this->storageId,
						'rootId' => $this->rootId
					]);
					continue;
				}

				try {
					$fileHandle = $file->fopen('rb');
				} catch (LockedException $e) {
					$retryQFiles[] = $queueFile;
					$this->logger->info('[IndexerJob] File ' . $file->getPath() . ' is locked, could not read for indexing. Adding it to the next batch.', ['storageId' => $this->storageId, 'rootId' => $this->rootId]);
					continue;
				} catch (NotPermittedException|\Throwable $e) {
					$this->logger->error('[IndexerJob] Could not open file ' . $file->getPath() . ' for reading', ['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
					continue;
				}
				if (!is_resource($fileHandle)) {
					$this->logger->warning('File handle for' . $file->getPath() . ' is not readable', ['storageId' => $this->storageId, 'rootId' => $this->rootId]);
					continue;
				}


				$size += $fileSize;
				$userIds = $this->storageService->getUsersForFileId($queueFile->getFileId());
				$sources[] = new Source(
					$userIds,
					ProviderConfigService::getSourceId($file->getId()),
					substr($file->getInternalPath(), 6), // remove 'files/' prefix
					$fileHandle,
					$file->getMtime(),
					$file->getMimeType(),
					ProviderConfigService::getDefaultProviderKey(),
				);
				$trackedQFiles[ProviderConfigService::getSourceId($file->getId())] = $queueFile;
				$allSourceIds[] = ProviderConfigService::getSourceId($file->getId());

				// Either the buffer is full, or we're at the last item
				if ($size > $maxSize || count($sources) >= Application::CC_MAX_FILES || $i === count($files) - 1) {
					try {
						$loadSourcesResult = $this->langRopeService->indexSources($sources);
						// track files
						$this->diagnosticService->sendIndexedFiles(count($loadSourcesResult['loaded_sources']));
						$loadedSources = array_merge($loadedSources, $loadSourcesResult['loaded_sources']);
						$sourcesToRetry = array_merge($sourcesToRetry, $loadSourcesResult['sources_to_retry']);
						$retryQFiles = array_merge($retryQFiles, array_map(fn ($sourceId) => $trackedQFiles[$sourceId], $loadSourcesResult['sources_to_retry']));
					} catch (RetryIndexException $e) {
						$this->logger->debug('At least one source is already being processed from another request, trying again soon', ['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
						$retryQFiles = array_merge($retryQFiles, array_map(fn (Source $source) => $trackedQFiles[$source->reference], $sources));
					} finally {
						// reset buffer
						$sources = [];
						$size = 0.0;
					}
				}
			} catch (InvalidPathException|NotFoundException $e) {
				$this->logger->error('[IndexerJob] Could not find file ' . $file->getPath(), ['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
				continue;
			}
		}

		$emptyInvalidSources = array_values(array_diff($allSourceIds, $loadedSources, $sourcesToRetry));
		if (count($emptyInvalidSources) > 0) {
			$this->logger->info('[IndexerJob] Invalid or empty files that were not indexed (n=' . count($emptyInvalidSources) . '): '
				. json_encode($this->exportFileSources($emptyInvalidSources), \JSON_THROW_ON_ERROR | \JSON_OBJECT_AS_ARRAY),
				['storageId' => $this->storageId, 'rootId' => $this->rootId]);
		}

		if (count($sourcesToRetry) > 0) {
			$this->logger->info('[IndexerJob] Files that were not indexed but will be retried (n=' . count($sourcesToRetry) . '): '
				. json_encode($this->exportFileSources($sourcesToRetry), \JSON_THROW_ON_ERROR | \JSON_OBJECT_AS_ARRAY),
				['storageId' => $this->storageId, 'rootId' => $this->rootId]);
		}

		try {
			$this->queue->removeFromQueue(array_values($trackedQFiles));
			// add files that were locked to the end of the queue
			foreach ($retryQFiles as $queueFile) {
				$this->queue->insertIntoQueue($queueFile);
			}
		} catch (Exception $e) {
			$this->logger->error('[IndexerJob] Could not prepare file queue for next iteration',
				['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
		}
	}

	private function setInitialIndexCompletion(): void {
		if ($this->appConfig->getAppValueInt('last_indexed_time', 0) !== 0) {
			return;
		}
		try {
			$queuedFilesCount = $this->queue->count();
		} catch (Exception $e) {
			$this->logger->warning('Could not count indexed files', ['exception' => $e]);
			return;
		}
		$crawlJobCount = $this->getJobCount(StorageCrawlJob::class);

		// if any storage crawler jobs are still running or there are still files in the queue, we are still crawling
		if ($crawlJobCount > 0 || $queuedFilesCount > 0) {
			return;
		}

		$this->logger->info('Initial index completion detected, setting last indexed time');
		$this->appConfig->setAppValueInt('last_indexed_time', $this->timeFactory->getTime(), false);
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

	private function exportFileSources(array $sources): array {
		return array_map(function ($sourceId) {
			$id = (int)(explode(' ', $sourceId)[1]);
			$node = $this->rootFolder->getFirstNodeById($id);
			if ($node === null) {
				return $id . ': <not found>';
			}
			return $id . ':' . $node->getPath();
		}, $sources);
	}
}
