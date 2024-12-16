<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2021-2022 The Recognize contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\BackgroundJobs;

use OCA\ContextChat\Service\StorageService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\QueuedJob;
use OCP\DB\Exception;
use Psr\Log\LoggerInterface;

class SchedulerJob extends QueuedJob {

	public function __construct(
		ITimeFactory $timeFactory,
		private LoggerInterface $logger,
		private IJobList $jobList,
		private StorageService $storageService,
	) {
		parent::__construct($timeFactory);
	}

	/**
	 * @throws Exception
	 */
	protected function run($argument): void {
		foreach ($this->storageService->getMounts() as $mount) {
			$this->logger->debug('Scheduling StorageCrawlJob storage_id=' . $mount['storage_id'] . ' root_id=' . $mount['root_id' ]);
			$this->jobList->add(StorageCrawlJob::class, [
				'storage_id' => $mount['storage_id'],
				'root_id' => $mount['root_id' ],
				'override_root' => $mount['override_root'],
				'last_file_id' => 0,
			]);
		}

		$this->jobList->remove(self::class);
	}
}
