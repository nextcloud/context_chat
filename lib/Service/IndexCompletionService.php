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
		private QueueService $queueService,
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
			$this->logger->warning('Could not get count of scheduled StorageCrawlJob jobs', ['exception' => $e]);
			return;
		}
		try {
			$queuedFilesCount = $this->queueService->count();
			if ($queuedFilesCount > 0) {
				$this->logger->debug('Files still in queue, intial indexing has not completed.', ['count' => $queuedFilesCount]);
				return;
			}
			$pendingActionsCount = $this->queueActionMapper->count();
			if ($pendingActionsCount > 0) {
				$this->logger->debug('Actions still pending (e.g. tag-based deletions), intial indexing has not completed.', ['count' => $pendingActionsCount]);
				return;
			}
			$this->logger->info('Initial index completion detected (live state check), setting last indexed time');
			$this->appConfig->setAppValueInt('last_indexed_time', $this->timeFactory->getTime(), lazy: true);
		} catch (\OCP\DB\Exception $e) {
			$this->logger->warning('Could not count queue items', ['exception' => $e]);
		}
	}
}
