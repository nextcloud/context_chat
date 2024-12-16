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
use OCA\ContextChat\Service\DiagnosticService;
use OCA\ContextChat\Service\QueueService;
use OCA\ContextChat\Service\StorageService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\DB\Exception;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

class StorageCrawlJob extends QueuedJob {
	public const BATCH_SIZE = 2000;

	public function __construct(
		ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private QueueService $queue,
		private IJobList $jobList,
		private StorageService $storageService,
		private DiagnosticService $diagnosticService,
		private IAppConfig $appConfig,
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * @param array{storage_id:int, root_id:int, override_root:int, last_file_id:int} $argument
	 * @return void
	 */
	protected function run($argument): void {
		$storageId = $argument['storage_id'];
		$rootId = $argument['root_id'];
		$overrideRoot = $argument['override_root'];
		$lastFileId = $argument['last_file_id'];

		// Remove current iteration
		$this->jobList->remove(self::class, $argument);

		$this->diagnosticService->sendHeartbeat(static::class, $this->getId());

		$i = 0;
		foreach ($this->storageService->getFilesInMount($storageId, $overrideRoot, $lastFileId, self::BATCH_SIZE) as $fileId) {
			$queueFile = new QueueFile();
			$queueFile->setStorageId($storageId);
			$queueFile->setRootId($rootId);
			$queueFile->setFileId($fileId);
			$queueFile->setUpdate(false);
			$this->diagnosticService->sendHeartbeat(static::class, $this->getId());
			try {
				$this->queue->insertIntoQueue($queueFile);
			} catch (Exception $e) {
				$this->logger->error('Failed to add file to queue', ['fileId' => $fileId, 'exception' => $e]);
			}
			$i++;
		}

		if ($i > 0) {
			// Schedule next iteration
			$this->jobList->add(self::class, [
				'storage_id' => $storageId,
				'root_id' => $rootId,
				'override_root' => $overrideRoot,
				'last_file_id' => $queueFile->getFileId(),
			]);

			// the last job to set this value will win
			$this->appConfig->setValueInt(Application::APP_ID, 'last_indexed_file_id', $queueFile->getFileId());
		}
	}
}
