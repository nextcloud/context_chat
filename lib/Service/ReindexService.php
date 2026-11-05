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
		private FetchGuard $fetchGuard,
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
	 *                                        providers that have not been imported yet are imported (which is all of them after a full wipe),
	 *                                        content providers explicitly specified are set as not initiated and scheduled for import.
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
	 * The documents the backend is still indexing are waited for here, so that its workers, possibly running
	 * on Kubernetes, cannot write them to the vector DB after it was wiped.
	 *
	 * A full wipe holds back all the queues until it is over, a wipe of the files only holds the files queue.
	 * A content provider is not held back since its unlocked/untouched queued items are deleted before the wait,
	 * so once the wait is over, none of what was queued before the wipe can still be indexed. Items the provider
	 * submits during the wipe may be indexed before or after the delete in the backend.
	 * It is safe to run this again when the backend request fails.
	 *
	 * @param list<string>|null $providerKeys null means everything including the actions queue, otherwise only the given providers
	 * @param bool $ignoreHandedOut do not wait for the documents the backend is indexing, for when some documents are stuck
	 *                              in the queue for 24h due to error or if the backend stopped while indexing them/having a lock
	 * @param ?callable(string): void $onStep called with a short description before each step, for progress output
	 * @throws \OCP\DB\Exception
	 * @throws \RuntimeException when the vector DB could not be wiped
	 */
	public function wipe(?array $providerKeys = null, bool $ignoreHandedOut = false, ?callable $onStep = null): void {
		$onStep = $onStep ?? static function (string $_): void {
		};

		$filesKey = ProviderConfigService::getDefaultProviderKey();
		$providerKeys = $providerKeys === null ? null : array_values(array_unique($providerKeys));
		$contentProviderKeys = $providerKeys === null ? [] : array_values(array_diff($providerKeys, [$filesKey]));

		if ($providerKeys === null) {
			$onStep('Pausing the hand-out of queued documents and actions to context_chat_backend');
		} elseif (in_array($filesKey, $providerKeys, true)) {
			$onStep('Pausing the hand-out of queued files to context_chat_backend');
		}
		// also stops the crawl and import jobs of the providers
		$pause = $this->fetchGuard->pause($providerKeys);
		try {
			if ($ignoreHandedOut) {
				$onStep('Not waiting for the background jobs and the documents context_chat_backend may still be indexing (--ignore-handed-out)');
			} else {
				// a running crawl or import would queue items after the queues are cleared
				$onStep('Checking for background jobs still queueing documents');
				$this->fetchGuard->waitForRunningJobs($providerKeys, static function (int $running) use ($onStep): void {
					$onStep('Waiting for ' . $running . ' background jobs to finish queueing documents');
				});
			}

			foreach ($contentProviderKeys as $providerKey) {
				$onStep('Removing the import job and clearing the indexing queue of ' . $providerKey);
				$this->clearContentProviderState($providerKey);
			}

			if (!$ignoreHandedOut) {
				$onStep('Checking for documents context_chat_backend is still indexing');
				$this->fetchGuard->waitForHandedOutDocuments($providerKeys, static function (int $locked) use ($onStep): void {
					$onStep('Waiting for context_chat_backend to finish indexing ' . $locked . ' documents');
				});
			}

			if ($providerKeys === null) {
				$this->wipeAll($onStep);
				return;
			}

			foreach ($providerKeys as $providerKey) {
				$this->logger->info('Wiping the Context Chat data of provider ' . $providerKey);
				if ($providerKey === $filesKey) {
					$onStep('Removing the crawl jobs, clearing the files indexing queue and the file system events, resetting the indexing statistics');
					$this->clearFilesState();
				} else {
					// `clearContentProviderState()` already ran so the content queue may be empty
					// but clean it up again since with --ignore-handed-out locked rows are not waited for to be cleared
					[$appId, $providerId] = $this->providerConfig->getProviderParts($providerKey);
					$this->contentQueue->removeProvider($appId, $providerId);
				}
				$onStep('Deleting the documents of ' . $providerKey . ' from the vector DB');
				$this->langRopeService->deleteProvider($providerKey);
			}
		} finally {
			if ($providerKeys === null) {
				$onStep('Resuming the hand-out of queued documents and actions');
			} elseif (in_array($filesKey, $providerKeys, true)) {
				$onStep('Resuming the hand-out of queued files');
			}
			$this->fetchGuard->resume($pause);
		}
	}

	/**
	 * @param callable(string): void $onStep
	 */
	private function wipeAll(callable $onStep): void {
		$this->logger->info('Wiping all the Context Chat data');

		$onStep('Removing the scheduled crawl and content import jobs');
		// stop the crawl and the content provider imports from queueing more items
		foreach ([SchedulerJob::class, StorageCrawlJob::class, InitialContentImportJob::class] as $jobClass) {
			$this->jobList->remove($jobClass);
		}
		$onStep('Clearing the indexing, actions and file system events queues');
		$this->clearTables(self::QUEUE_TABLES);
		$onStep('Resetting the indexing statistics and the content providers\' import state');
		$this->resetStats();
		$this->providerConfig->resetProvidersInitiation();

		$onStep('Deleting all the documents from the vector DB');
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

	/**
	 * Leaves the items the backend is indexing in the queue, so the wipe can wait for them, and deletes the rest.
	 * Deleted items can no longer be locked, so nothing more of the provider's queue can be handed out.
	 */
	private function clearContentProviderState(string $providerKey): void {
		$provider = $this->providerConfig->getProvider($providerKey);
		if ($provider !== null && $provider['classString'] !== '') {
			$this->jobList->remove(InitialContentImportJob::class, $provider['classString']);
		}

		[$appId, $providerId] = $this->providerConfig->getProviderParts($providerKey);
		$this->contentQueue->removeProvider($appId, $providerId, onlyUnlocked: true);

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
