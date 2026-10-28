<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\BackgroundJobs\InitialContentImportJob;
use OCA\ContextChat\BackgroundJobs\SchedulerJob;
use OCA\ContextChat\BackgroundJobs\StorageCrawlJob;
use OCA\ContextChat\Logger;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;

class ReindexService {
	public const QUEUE_TABLES = [
		'context_chat_queue',
		'context_chat_content_queue',
		'context_chat_action_queue',
		'context_chat_fs_events',
	];

	private const STATS_CONFIG_KEYS = [
		'last_indexed_time',
		'last_enqueued_db_id',
		'eligible_files_count',
		'eligible_files_count_time',
	];

	public function __construct(
		private IDBConnection $db,
		private IJobList $jobList,
		private IAppConfig $appConfig,
		private ProviderConfigService $providerConfig,
		private LangRopeService $langRopeService,
		private ITimeFactory $timeFactory,
		private Logger $logger,
	) {
	}

	/**
	 * Schedule the full filesystem crawl and the initial import of every content provider that has not
	 * been imported yet. Already scheduled jobs are not scheduled again.
	 *
	 * @return bool whether the filesystem crawl was scheduled, false if it was already scheduled
	 */
	public function scheduleReindex(): bool {
		foreach ($this->providerConfig->getProviders() as $provider) {
			if ($provider['isInitiated'] || $provider['classString'] === ''
				|| $this->jobList->has(InitialContentImportJob::class, $provider['classString'])
			) {
				continue;
			}
			$this->jobList->add(InitialContentImportJob::class, $provider['classString']);
		}

		if ($this->jobList->has(SchedulerJob::class, null)) {
			return false;
		}
		$this->jobList->add(SchedulerJob::class);
		return true;
	}

	/**
	 * Delete everything that was queued or indexed, here and in context_chat_backend's vector DB.
	 * The local state is cleared first so nothing new is handed to the backend while it is wiped.
	 * It is safe to run this again when the backend request fails.
	 *
	 * @throws \OCP\DB\Exception
	 * @throws \RuntimeException when the vector DB could not be wiped
	 */
	public function wipe(): void {
		$this->logger->info('Wiping all the Context Chat data');

		// stop the crawl and the content provider imports from queueing more items
		foreach ([SchedulerJob::class, StorageCrawlJob::class, InitialContentImportJob::class] as $jobClass) {
			$this->jobList->remove($jobClass);
		}

		$this->db->beginTransaction();
		try {
			foreach (self::QUEUE_TABLES as $table) {
				$this->db->getQueryBuilder()->delete($table)->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		foreach (self::STATS_CONFIG_KEYS as $key) {
			$this->appConfig->deleteAppValue($key);
		}
		// the time taken for the next complete index is measured from here
		$this->appConfig->setAppValueInt('installed_time', $this->timeFactory->getTime(), lazy: true);
		$this->providerConfig->resetProvidersInitiation();

		$this->langRopeService->deleteAll();
		$this->logger->info('Wiped all the Context Chat data');
	}
}
