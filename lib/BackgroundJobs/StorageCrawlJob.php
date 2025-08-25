<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2021-2022 The Recognize contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\DiagnosticService;
use OCA\ContextChat\Service\QueueService;
use OCA\ContextChat\Service\StorageService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\DB\Exception;
use OCP\IAppConfig;

class StorageCrawlJob extends QueuedJob {
	public const BATCH_SIZE = 2000;
	public const DEFAULT_JOB_INTERVAL = 5 * 60;

	public function __construct(
		ITimeFactory $timeFactory,
		private Logger $logger,
		private QueueService $queue,
		private IJobList $jobList,
		private StorageService $storageService,
		private DiagnosticService $diagnosticService,
		private IAppConfig $appConfig,
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * @param array{storage_id:int, root_id:int, overridden_root:int|null, override_root:int|null, last_file_id:int} $argument
	 * @return void
	 */
	protected function run($argument): void {
		$storageId = $argument['storage_id'];
		$rootId = $argument['root_id'];
		$overrideRoot = $argument['overridden_root'] ?? $argument['override_root'] ?? $rootId;
		$lastFileId = $argument['last_file_id'];

		// Remove current iteration
		$this->jobList->remove(self::class, $argument);

		try {
			$this->diagnosticService->sendJobStart(static::class, $this->getId());
			$this->diagnosticService->sendHeartbeat(static::class, $this->getId());

			$mountFilesCount = 0;
			$lastSuccessfulFileId = -1;
			foreach ($this->storageService->getFilesInMount($storageId, $overrideRoot ?? $rootId, $lastFileId, self::BATCH_SIZE) as $fileId) {
				$queueFile = new QueueFile();
				$queueFile->setStorageId($storageId);
				$queueFile->setRootId($rootId);
				$queueFile->setFileId($fileId);
				$queueFile->setUpdate(false);
				$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
				try {
					$this->queue->insertIntoQueue($queueFile);
					$lastSuccessfulFileId = $fileId;
				} catch (Exception $e) {
					$this->logger->error('[StorageCrawlJob] Failed to add file to queue', [
						'fileId' => $fileId,
						'exception' => $e,
						'storage_id' => $storageId,
						'root_id' => $rootId,
						'override_root' => $overrideRoot,
						'last_file_id' => $lastFileId
					]);
				}
				$mountFilesCount++;
			}

			if ($mountFilesCount > 0) {
				// Schedule next iteration after 5 minutes
				$this->jobList->scheduleAfter(self::class, $this->time->getTime() + $this->getJobInterval(), [
					'storage_id' => $storageId,
					'root_id' => $rootId,
					'override_root' => $overrideRoot,
					'last_file_id' => $queueFile->getFileId(),
				]);

				if ($lastSuccessfulFileId !== -1) {
					// the last job to set this value will win
					$this->appConfig->setValueInt(Application::APP_ID, 'last_indexed_file_id', $lastSuccessfulFileId);
				}
			}
		} finally {
			$this->diagnosticService->sendJobEnd(static::class, $this->getId());
		}
	}

	protected function getJobInterval(): int {
		return $this->appConfig->getValueInt(Application::APP_ID, 'crawl_job_interval', self::DEFAULT_JOB_INTERVAL);
	}
}
