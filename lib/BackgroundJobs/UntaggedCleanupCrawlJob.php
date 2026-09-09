<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\FsEventService;
use OCA\ContextChat\Service\IndexCompletionService;
use OCA\ContextChat\Service\StorageService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\Files\IRootFolder;

/**
 * For a single mount, walks all files in batches and removes from the
 * index any file that does not have the "AI knowledge" tag. Mirrors
 * StorageCrawlJob's batching/self-rescheduling shape, but for cleanup
 * instead of indexing.
 */
class UntaggedCleanupCrawlJob extends QueuedJob {
	public const BATCH_SIZE = 2000;
	public const JOB_INTERVAL = 60;

	public function __construct(
		ITimeFactory $timeFactory,
		private Logger $logger,
		private IJobList $jobList,
		private StorageService $storageService,
		private FsEventService $fsEventService,
		private IRootFolder $rootFolder,
		private IndexCompletionService $indexCompletionService,
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * @param array{storage_id:int, root_id:int, last_file_id:int|null} $argument
	 * @return void
	 */
	protected function run($argument): void {
		$storageId = $argument['storage_id'];
		$rootId = $argument['root_id'];
		$lastFileId = ($argument['last_file_id'] ?? null) === null ? 0 : $argument['last_file_id'];

		// Remove current iteration
		$this->jobList->remove(self::class, $argument);

		$mountFilesCount = 0;
		$lastFileIdSeen = $lastFileId;
		foreach ($this->storageService->getFilesInMount($storageId, $rootId, $lastFileId, self::BATCH_SIZE) as $fileId) {
			$lastFileIdSeen = $fileId;
			$mountFilesCount++;

			$node = $this->rootFolder->getFirstNodeById($fileId);
			if ($node === null) {
				continue;
			}

			if (!$this->fsEventService->hasAiKnowledgeTag($node)) {
				$this->fsEventService->onDelete($node, false);
			}
		}

		if ($mountFilesCount > 0) {
			// Schedule next batch
			$this->jobList->scheduleAfter(self::class, $this->time->getTime() + self::JOB_INTERVAL, [
				'storage_id' => $storageId,
				'root_id' => $rootId,
				'last_file_id' => $lastFileIdSeen,
			]);
		} else {
			$this->logger->info('[UntaggedCleanupCrawlJob] Finished mount storage_id=' . $storageId . ' root_id=' . $rootId);
			$this->indexCompletionService->checkAndMarkComplete();
		}
	}
}
