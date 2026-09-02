<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\StorageService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;

/**
 * Discovers all mounts and schedules an UntaggedCleanupCrawlJob for each,
 * mirroring SchedulerJob's shape.
 */
class UntaggedCleanupSchedulerJob extends QueuedJob {
	public function __construct(
		ITimeFactory $timeFactory,
		private Logger $logger,
		private IJobList $jobList,
		private StorageService $storageService,
		private IAppConfig $appConfig,
	) {
		parent::__construct($timeFactory);
	}

	protected function run($argument): void {
		// Mirror SchedulerJob: a fresh cleanup run means the initial-indexing
		// status is no longer settled until this run finishes.
		$this->appConfig->setAppValueInt('last_indexed_time', 0, lazy: true);
		foreach ($this->storageService->getMounts() as $mount) {
			$this->logger->debug('[UntaggedCleanupSchedulerJob] Scheduling cleanup storage_id=' . $mount['storage_id'] . ' root_id=' . $mount['root_id']);
			$this->jobList->add(UntaggedCleanupCrawlJob::class, [
				'storage_id' => $mount['storage_id'],
				'root_id' => $mount['root_id'],
				'last_file_id' => 0,
			]);
		}
		$this->jobList->remove(self::class);
	}
}
