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
	private const INDEX_COMPLETION_THRESHOLD = 0.02; // 2%

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
	 * @throws \OCP\DB\Exception
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
		} catch (\OCP\DB\Exception $e) {
			$this->logger->error('[IndexerJob] Cannot retrieve items from  queue', ['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
			return;
		}

		try {
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
		} catch (\OCP\DB\Exception $e) {
			$this->logger->error('[IndexerJob] Cannot retrieve items from queue', ['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
		} catch (\Throwable $e) {
			$this->logger->error('[IndexerJob] Failure during job run', ['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
		} finally {
			$this->diagnosticService->sendJobEnd(static::class, $this->getId());
		}
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
		$visitedQFiles = [];
		$size = 0.0;

		foreach ($files as $i => $queueFile) {
			$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
			if ($startTime + $maxTime < time()) {
				break;
			}

			// these files have already been processed, remove them from the queue later
			// this includes files that are too large, locked, or not readable
			$visitedQFiles[] = $queueFile;

			$file = current($this->rootFolder->getById($queueFile->getFileId()));
			if (!$file instanceof File) {
				continue;
			}

			try {
				$fileHandle = $file->fopen('rb');
				// get the file size here to ensure "InvalidPathException|NotFoundException" is thrown
				// once before we try to read the file so we can skip the file cleanly
				$fileSize = (float)$file->getSize();
			} catch (LockedException $e) {
				$retryQFiles[] = $queueFile;
				$this->logger->info('[IndexerJob] File ' . $file->getPath() . ' is locked, could not read for indexing. Adding it to the next batch.', ['storageId' => $this->storageId, 'rootId' => $this->rootId]);
				continue;
			} catch (InvalidPathException|NotFoundException $e) {
				$this->logger->error('[IndexerJob] Could not find file ' . $file->getPath(), ['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
				continue;
			} catch (NotPermittedException|\Throwable $e) {
				$this->logger->error('[IndexerJob] Could not open file ' . $file->getPath() . ' for reading', ['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
				continue;
			}
			if (!is_resource($fileHandle)) {
				$this->logger->warning('File handle for' . $file->getPath() . ' is not readable', ['storageId' => $this->storageId, 'rootId' => $this->rootId]);
				continue;
			}

			if ($fileSize > $maxSize) {
				$this->logger->info('[IndexerJob] File is too large to index', [
					'size' => $fileSize,
					'maxSize' => $maxSize,
					'nodeId' => $file->getId(),
					'path' => $file->getPath(),
					'storageId' => $this->storageId,
					'rootId' => $this->rootId,
				]);
				continue;
			}

			$size += $fileSize;
			$userIds = $this->storageService->getUsersForFileId($queueFile->getFileId());
			$sources[] = new Source(
				$userIds,
				ProviderConfigService::getSourceId($file->getId()),
				$file->getInternalPath() ?: $file->getPath() ?: $file->getName(),
				$fileHandle,
				$file->getMtime(),
				$file->getMimeType(),
				ProviderConfigService::getDefaultProviderKey(),
			);
			$allSourceIds[] = ProviderConfigService::getSourceId($file->getId());

			// Either the buffer is full, or we're at the last item
			if ($size > $maxSize || count($sources) >= Application::CC_MAX_FILES || $i === count($files) - 1) {
				try {
					$innerStartTime = time();
					$loadSourcesResult = $this->langRopeService->indexSources($sources);
					$this->logger->info('[IndexerJob] Indexed ' . count($loadSourcesResult['loaded_sources']) . ' files', [
						'storageId' => $this->storageId,
						'rootId' => $this->rootId,
						'loadedSources' => $loadSourcesResult['loaded_sources'],
						'sourcesToRetry' => $loadSourcesResult['sources_to_retry'],
						'timeTaken' => time() - $innerStartTime,
						'totalSize' => $size,
						'sources' => $this->exportFileSources(array_map(fn (Source $source) => $source->reference, $sources)),
					]);
					// track sent files count
					$this->diagnosticService->sendIndexedFiles(count($loadSourcesResult['loaded_sources']));
					$loadedSources = array_merge($loadedSources, $loadSourcesResult['loaded_sources']);
					$sourcesToRetry = array_merge($sourcesToRetry, $loadSourcesResult['sources_to_retry']);
				} catch (RetryIndexException $e) {
					$this->logger->debug('At least one source is already being processed from another request, trying again soon', ['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
					$sourcesToRetry = array_merge($sourcesToRetry, array_map(fn (Source $source) => $source->reference, $sources));
				} catch (\Exception $e) {
					$this->logger->error('[IndexerJob] Error while indexing sources', [
						'exception' => $e,
						'storageId' => $this->storageId,
						'rootId' => $this->rootId,
						'sources' => $this->exportFileSources(array_map(fn (Source $source) => $source->reference, $sources)),
					]);
					// If we have an error, we retry all sources in the next run
					$sourcesToRetry = array_merge($sourcesToRetry, array_map(fn (Source $source) => $source->reference, $sources));
				} finally {
					// reset buffer
					$sources = [];
					$size = 0.0;
				}
			}
		}

		foreach ($files as $queueFile) {
			if (in_array(ProviderConfigService::getSourceId($queueFile->getFileId()), $sourcesToRetry, true)) {
				$retryQFiles[] = $queueFile;
			}
		}

		$emptyInvalidSources = array_values(array_diff($allSourceIds, $loadedSources, $sourcesToRetry));
		$this->logger->info('[IndexerJob] Batch processed', [
			'storageId' => $this->storageId,
			'rootId' => $this->rootId,
			'nFilesProcessed' => count($files),
			'nFilesEligible' => count($allSourceIds),
			'nFilesLoaded' => count($loadedSources),
			'nFilesToRetry' => count($sourcesToRetry),
			'nFilesInvalidOrEmpty' => count($emptyInvalidSources),
			'nRetryQFiles' => count($retryQFiles),
			'filesProcessed' => $this->exportFileSources(
				array_map(fn (QueueFile $f) => ProviderConfigService::getSourceId($f->getFileId()), $files)
			),
			'filesEligible' => $this->exportFileSources($allSourceIds),
			'filesLoaded' => $this->exportFileSources($loadedSources),
			'filesToRetry' => $this->exportFileSources($sourcesToRetry),
			'filesInvalidOrEmpty' => $this->exportFileSources($emptyInvalidSources),
			'retryQFiles' => $this->exportFileSources(
				array_map(fn (QueueFile $f) => ProviderConfigService::getSourceId($f->getFileId()), $retryQFiles)
			),
		]);

		try {
			$this->queue->removeFromQueue($visitedQFiles);
			// add retryable files to the end of the queue
			foreach ($retryQFiles as $queueFile) {
				$this->queue->insertIntoQueue($queueFile);
			}
		} catch (\OCP\DB\Exception $e) {
			$this->logger->error('[IndexerJob] Could not prepare file queue for next iteration',
				['exception' => $e, 'storageId' => $this->storageId, 'rootId' => $this->rootId]);
		}
	}

	private function setInitialIndexCompletion(): void {
		if ($this->appConfig->getAppValueInt('last_indexed_time', 0) !== 0) {
			return;
		}
		try {
			$queuedNewFilesCount = $this->queue->countNewFiles();
			$eligibleFilesCount = $this->storageService->countFiles();
			// if the new files in the queue are less than 2% of the total eligible files, we consider the
			// initial indexing complete this allows for some margin of error in case some files were
			// added while we were indexing but still ensures that we have indexed the vast majority of
			// files at least once
			$thresholdCount = (float)$eligibleFilesCount * self::INDEX_COMPLETION_THRESHOLD;
		} catch (\OCP\DB\Exception $e) {
			$this->logger->warning('Could not count queued new files or total eligible files', ['exception' => $e]);
			return;
		}
		$crawlJobCount = $this->getJobCount(StorageCrawlJob::class);

		// if any storage crawler jobs are still running or there are still new files in the queue,
		// we are still indexing files that were never indexed before.
		if ($crawlJobCount > 0 || $queuedNewFilesCount > $thresholdCount) {
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
		$defaultProviderKey = ProviderConfigService::getDefaultProviderKey();
		return array_map(function ($sourceId) use ($defaultProviderKey) {
			$id = intval(ProviderConfigService::getItemId($sourceId, $defaultProviderKey));
			if ($id === 0) {
				return $sourceId . ': <not found>';
			}
			$node = $this->rootFolder->getFirstNodeById($id);
			if ($node === null) {
				return $id . ': <not found>';
			}
			return $id . ':' . $node->getPath();
		}, $sources);
	}
}
