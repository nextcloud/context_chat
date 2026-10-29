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
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Logger;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IDBConnection;

class ReindexService {
	// queues that only hold files
	private const FILES_QUEUE_TABLES = [
		'context_chat_queue',
		'context_chat_fs_events',
	];

	public const QUEUE_TABLES = [
		...self::FILES_QUEUE_TABLES,
		'context_chat_content_queue',
		'context_chat_action_queue',
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
		private QueueContentItemMapper $contentQueue,
		private ITimeFactory $timeFactory,
		private Logger $logger,
	) {
	}

	/**
	 * Keys of the providers that can be reindexed: the files and the registered content providers.
	 *
	 * @return list<string>
	 */
	public function getRegisteredProviderKeys(): array {
		return $this->uniqueSorted(array_merge(
			[ProviderConfigService::getDefaultProviderKey()],
			array_keys($this->providerConfig->getProviders()),
		));
	}

	/**
	 * Keys of the providers that can be wiped: the registered ones and the ones that still have
	 * documents in the vector DB, e.g. of an app that has been removed since.
	 *
	 * @return list<string>
	 * @throws \RuntimeException when the backend could not be reached
	 */
	public function getWipeableProviderKeys(): array {
		return $this->uniqueSorted(array_merge(
			$this->getRegisteredProviderKeys(),
			array_keys($this->langRopeService->getIndexedDocumentsCounts()),
		));
	}

	/**
	 * @param array<array-key, string|int> $keys
	 * @return list<string>
	 */
	private function uniqueSorted(array $keys): array {
		$keys = array_values(array_unique(array_map(strval(...), $keys)));
		sort($keys);
		return $keys;
	}

	/**
	 * Schedule the full filesystem crawl and the initial import of the content providers.
	 * Jobs that are already scheduled are not scheduled again.
	 *
	 * @param list<string>|null $providerKeys null means everything. Without a scope only the content
	 *   providers that have not been imported yet are imported (which is all of them after a full wipe),
	 *   content providers explicitly specified are set as not initiated and scheduled for import.
	 * @return bool whether anything new was scheduled
	 */
	public function scheduleReindex(?array $providerKeys = null): bool {
		$scheduled = false;
		$filesKey = ProviderConfigService::getDefaultProviderKey();

		foreach ($this->providerConfig->getProviders() as $key => $provider) {
			if ($key === $filesKey || $provider['classString'] === '') {
				continue;
			}
			if ($providerKeys === null) {
				// a full wipe turns all providers as not initiated so they will be scheduled for import and not skipped
				if ($provider['isInitiated']) {
					continue;
				}
			} elseif (in_array($key, $providerKeys, true)) {
				$this->providerConfig->setProviderInitiated($key, false);
			} else {
				continue;
			}
			if ($this->jobList->has(InitialContentImportJob::class, $provider['classString'])) {
				continue;
			}
			$this->jobList->add(InitialContentImportJob::class, $provider['classString']);
			$scheduled = true;
		}

		if (($providerKeys === null || in_array($filesKey, $providerKeys, true))
			&& !$this->jobList->has(SchedulerJob::class, null)
		) {
			$this->jobList->add(SchedulerJob::class);
			$scheduled = true;
		}
		return $scheduled;
	}

	/**
	 * Delete everything that was queued or indexed, here and in context_chat_backend's vector DB.
	 * The local state is cleared first so nothing new is handed to the backend while it is wiped.
	 * It is safe to run this again when the backend request fails.
	 *
	 * @param list<string>|null $providerKeys null means everything including the actions queue, otherwise only the given providers
	 * @throws \OCP\DB\Exception
	 * @throws \RuntimeException when the vector DB could not be wiped
	 */
	public function wipe(?array $providerKeys = null): void {
		if ($providerKeys === null) {
			$this->wipeAll();
			return;
		}

		foreach (array_unique($providerKeys) as $providerKey) {
			$this->logger->info('Wiping the Context Chat data of provider ' . $providerKey);
			if ($providerKey === ProviderConfigService::getDefaultProviderKey()) {
				$this->clearFilesState();
			} else {
				$this->clearContentProviderState($providerKey);
			}
			$this->langRopeService->deleteProvider($providerKey);
		}
	}

	private function wipeAll(): void {
		$this->logger->info('Wiping all the Context Chat data');

		// stop the crawl and the content provider imports from queueing more items
		foreach ([SchedulerJob::class, StorageCrawlJob::class, InitialContentImportJob::class] as $jobClass) {
			$this->jobList->remove($jobClass);
		}
		$this->clearTables(self::QUEUE_TABLES);
		$this->resetStats();
		$this->providerConfig->resetProvidersInitiation();

		$this->langRopeService->deleteAll();
		$this->logger->info('Wiped all the Context Chat data');
	}

	/**
	 * The actions queue is left alone, it is shared by all the providers and the users.
	 * Leftover actions for the wiped documents are skipped by the backend.
	 */
	private function clearFilesState(): void {
		foreach ([SchedulerJob::class, StorageCrawlJob::class] as $jobClass) {
			$this->jobList->remove($jobClass);
		}
		$this->clearTables(self::FILES_QUEUE_TABLES);
		// the stats only track the files
		$this->resetStats();
	}

	private function clearContentProviderState(string $providerKey): void {
		$provider = $this->providerConfig->getProvider($providerKey);
		if ($provider !== null && $provider['classString'] !== '') {
			$this->jobList->remove(InitialContentImportJob::class, $provider['classString']);
		}

		[$appId, $providerId] = $this->providerConfig->getProviderParts($providerKey);
		$this->contentQueue->removeProvider($appId, $providerId);

		if ($provider !== null) {
			$this->providerConfig->setProviderInitiated($providerKey, false);
		}
	}

	/**
	 * @param list<string> $tables
	 * @throws \RuntimeException
	 */
	private function clearTables(array $tables): void {
		$this->db->beginTransaction();
		try {
			foreach ($tables as $table) {
				$this->db->getQueryBuilder()->delete($table)->executeStatement();
			}
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw new \RuntimeException('Failed to clear tables ' . implode(', ', $tables) . ': ' . $e->getMessage(), 0, $e);
		}
	}

	private function resetStats(): void {
		foreach (self::STATS_CONFIG_KEYS as $key) {
			$this->appConfig->deleteAppValue($key);
		}
		// the time taken for the next complete index is measured from here
		$this->appConfig->setAppValueInt('installed_time', $this->timeFactory->getTime(), lazy: true);
	}
}
