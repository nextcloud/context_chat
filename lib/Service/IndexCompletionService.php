<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\BackgroundJobs\StorageCrawlJob;
use OCA\ContextChat\BackgroundJobs\UntaggedCleanupCrawlJob;
use OCA\ContextChat\Db\QueueActionMapper;
use OCA\ContextChat\Db\QueueMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

/**
 * Checks whether the initial full indexing run has completed, and marks it
 * as such (setting app config 'last_indexed_time'). Extracted from
 * QueueController so that BOTH the normal indexing flow (via QueueController)
 * AND the tag-based cleanup flow (via UntaggedCleanupCrawlJob) can trigger
 * this check when they finish their own work, instead of relying solely on
 * the document-queue DELETE endpoint being called.
 */
class IndexCompletionService {
	public function __construct(
		private LoggerInterface $logger,
		private IAppConfig $appConfig,
		private QueueMapper $queueMapper,
		private QueueActionMapper $queueActionMapper,
		private IJobList $jobList,
		private ITimeFactory $timeFactory,
	) {
	}

	/**
	 * @template T of \OCP\BackgroundJob\Job
	 * @psalm-param T::class $jobClass
	 */
	private function getJobCount(string $jobClass): int {
		$countByClass = array_values(array_filter($this->jobList->countByClass(), fn ($row) => $row['class'] == $jobClass));
		return count($countByClass) > 0 ? $countByClass[0]['count'] : 0;
	}

	public function checkAndMarkComplete(): void {
		if ($this->appConfig->getAppValueInt('last_indexed_time', 0, lazy: true) !== 0) {
			return;
		}

		try {
			$crawlJobCount = $this->getJobCount(StorageCrawlJob::class) + $this->getJobCount(UntaggedCleanupCrawlJob::class);
			if ($crawlJobCount > 0) {
				$this->logger->debug('A crawl job (indexing or tag cleanup) is still scheduled for execution, intial indexing has not completed.');
				return;
			}
		} catch (\Exception $e) {
			$this->logger->warning('Could not get count of scheduled crawl jobs', ['exception' => $e]);
			return;
		}

		try {
			$pendingActionsCount = $this->queueActionMapper->count();
			if ($pendingActionsCount > 0) {
				$this->logger->debug('Actions still pending (e.g. tag-based deletions), intial indexing has not completed.', ['count' => $pendingActionsCount]);
				return;
			}
		} catch (\Exception $e) {
			$this->logger->warning('Could not count pending queue actions', ['exception' => $e]);
			return;
		}

		try {
			$lastEnqueuedDbId = $this->appConfig->getAppValueInt('last_enqueued_db_id', -1, lazy: true);
			if ($lastEnqueuedDbId === -1) {
				// Instances that completed their crawl before this value was introduced never got
				// it set, and the migration only backfills it while the referenced file is still
				// queued. Seed it from the queue instead of falling through to counting every file
				// in the file cache on every single request.
				$maxQueuedDbId = $this->queueMapper->getMaxId();
				if ($maxQueuedDbId === null) {
					// no crawl jobs left and nothing queued, so everything has been indexed once
					$this->logger->info('Initial index completion detected, setting last indexed time');
					$this->appConfig->setAppValueInt('last_indexed_time', $this->timeFactory->getTime(), lazy: true);
					return;
				}
				$this->appConfig->setAppValueInt('last_enqueued_db_id', $maxQueuedDbId, lazy: true);
				$lastEnqueuedDbId = $maxQueuedDbId;
			}

			$initiallyQueuedFilesExist = $this->queueMapper->existsQueueItemsUpToDbId($lastEnqueuedDbId);
			if ($initiallyQueuedFilesExist) {
				$this->logger->debug('Initially queued files still in the queue, intial indexing has not completed.');
				return;
			}
			$this->logger->info('Initial index completion detected, setting last indexed time');
			$this->appConfig->setAppValueInt('last_indexed_time', $this->timeFactory->getTime(), lazy: true);
			return;
		} catch (\Exception $e) {
			$this->logger->warning('Could not get last enqueued file\'s DB id', ['exception' => $e]);
		}

		// last enqueued file's ID could not be retrieved, fall back to checking whether the queue is
		// empty. With no crawl jobs left, nothing more will be queued for the initial index.
		try {
			if ($this->queueMapper->getMaxId() === null) {
				$this->logger->info('Initial index completion detected, setting last indexed time');
				$this->appConfig->setAppValueInt('last_indexed_time', $this->timeFactory->getTime(), lazy: true);
			}
		} catch (\Exception $e) {
			$this->logger->warning('Could not check whether the queue is empty', ['exception' => $e]);
		}
	}
}
