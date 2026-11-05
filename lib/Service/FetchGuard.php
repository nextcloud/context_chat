<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Db\QueueMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;

/**
 * Stops handing out queue items to context_chat_backend while its vector DB is wiped.
 * On Kubernetes the backend's indexing and updates pods are not the one serving the wipe request, so
 * they can only be held back from here.
 *
 * A full wipe holds back everything while a wipe of the files provider only the files. A wipe of any
 * content provider does not pause, it clears the provider's queue before it waits for the handed out documents
 * instead. It only stops the provider's import job.
 *
 * A pause expires on its own after a day, so a wipe that dies half way does not stop the indexing forever.
 * Running the wipe again lifts it right away: it starts a new pause and lifts that one when it ends.
 */
class FetchGuard {
	private const ALL_KEY = 'queue_fetch_paused_until';
	private const FILES_KEY = 'files_fetch_paused_until';
	// a wipe of a content provider, for its import job: ("paused_until_<md5-of-the-provider-key>" => until))
	private const PROVIDER_KEY_PREFIX = 'paused_until_';
	// a background job queueing items: ("running_<files|md5-of-the-provider-key>_<job-id>" => until)
	private const RUNNING_KEY_PREFIX = 'running_';
	// pause indexing while wipe happens, max 1 day so a crashed process does not stop it indefinitely
	private const PAUSE_DURATION = 24 * 60 * 60;
	public const DEFAULT_DRAIN_TIMEOUT = 30 * 60;  // 30 mins for the currently indexing documents to be done
	// bg jobs except initial import refreshes its record while it runs, a killed job blocks wipes for at most this long
	public const RUNNING_TTL = 10 * 60;
	// an initial import job cannot refresh its record while the app imports, so it has to last for the whole import
	public const IMPORT_RUNNING_TTL = 60 * 60;

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private QueueMapper $queueMapper,
		private QueueContentItemMapper $contentQueue,
	) {
	}

	/**
	 * Starts a wipe: what is queued for these providers is held back until resume()
	 *
	 * @param list<string>|null $providerKeys null for a full wipe
	 * @return array<string, int> the pause object, to be passed to resume()
	 */
	public function pause(?array $providerKeys = null): array {
		$until = $this->timeFactory->getTime() + self::PAUSE_DURATION;
		$keys = $providerKeys === null ? [self::ALL_KEY] : array_map($this->getPauseKey(...), $providerKeys);
		$pause = [];
		foreach ($keys as $key) {
			$this->appConfig->setAppValueInt($key, $until, lazy: true);
			$pause[$key] = $until;
		}
		return $pause;
	}

	/**
	 * Lifts the pause, only the ones started by the caller, with a matching until
	 *
	 * @param array<string, int> $pause
	 */
	public function resume(array $pause): void {
		foreach ($pause as $key => $until) {
			if ($this->readUntil($key) === $until) {
				$this->appConfig->deleteAppValue($key);
			}
		}
	}

	/**
	 * Whether a wipe holds back:
	 * - null: everything, nothing is handed out, not even actions. A full wipe is running.
	 * - the files: the files, they are neither handed out nor crawled. A full wipe or a wipe of the files is running.
	 * - a content provider: its import job, which must stop. A full wipe or a wipe of the provider is running.
	 *     Its queued items are still handed out, unless it is a full wipe.
	 */
	public function isPaused(?string $providerKey = null): bool {
		$now = $this->timeFactory->getTime();
		if ($this->readUntil(self::ALL_KEY) > $now) {
			return true;
		}
		return $providerKey !== null && $this->readUntil($this->getPauseKey($providerKey)) > $now;
	}

	private function getPauseKey(string $providerKey): string {
		// keep the key length within 64 chars
		return $providerKey === ProviderConfigService::getDefaultProviderKey()
			? self::FILES_KEY
			: self::PROVIDER_KEY_PREFIX . md5($providerKey);
	}

	/**
	 * Records a background job queueing items of a provider, until it calls jobEnded(). A wipe of the
	 * provider waits for it with waitForRunningJobs().
	 *
	 * The job records itself before it checks isPaused(), and a wipe pauses before it looks for running
	 * jobs, so either the wipe waits for the job or the job sees the wipe and stops.
	 *
	 * @return string the record, to be passed to jobAlive() and jobEnded()
	 */
	public function jobStarted(string $providerKey, int|string $jobId, int $ttl = self::RUNNING_TTL): string {
		$key = self::RUNNING_KEY_PREFIX . $this->getProviderConfigPartKey($providerKey) . '_' . $jobId;
		$this->appConfig->setAppValueInt($key, $this->timeFactory->getTime() + $ttl, lazy: true);
		return $key;
	}

	/**
	 * Extends the record of a job that is still running, at most once per half of its lifetime.
	 */
	public function jobAlive(string $record): void {
		$until = $this->appConfig->getAppValueInt($record, 0, lazy: true);
		$now = $this->timeFactory->getTime();
		if ($until - $now < self::RUNNING_TTL / 2) {
			$this->appConfig->setAppValueInt($record, $now + self::RUNNING_TTL, lazy: true);
		}
	}

	public function jobEnded(string $record): void {
		$this->appConfig->deleteAppValue($record);
	}

	private function getProviderConfigPartKey(string $providerKey): string {
		// a provider key can be longer than an app config key may be
		return $providerKey === ProviderConfigService::getDefaultProviderKey() ? 'files' : md5($providerKey);
	}

	/**
	 * @param list<string> $prefixes
	 */
	private function countRunningJobs(array $prefixes): int {
		$now = $this->timeFactory->getTime();
		$count = 0;
		foreach ($prefixes as $prefix) {
			foreach ($this->readValues($prefix, isPrefix: true) as $key => $until) {
				if ((int)$until > $now) {
					$count++;
				} else {
					// left by a job that was killed
					$this->appConfig->deleteAppValue($key);
				}
			}
		}
		return $count;
	}

	/**
	 * @param list<string>|null $providerKeys
	 * @throws \OCP\DB\Exception
	 */
	private function countHandedOutDocuments(?array $providerKeys): int {
		$count = 0;
		if ($providerKeys === null || in_array(ProviderConfigService::getDefaultProviderKey(), $providerKeys, true)) {
			$count += $this->queueMapper->countLocked();
		}
		foreach ($this->contentQueue->countLocked() as $providerKey => $locked) {
			if ($providerKeys === null || in_array($providerKey, $providerKeys, true)) {
				$count += (int)$locked;
			}
		}
		return $count;
	}

	/**
	 * Waits for the background jobs that queue items of these providers to end. A job that is still running
	 * would queue items, or mark its provider imported, after the wipe cleared them.
	 *
	 * @param list<string>|null $providerKeys null for the jobs of all the providers
	 * @param ?callable(int): void $onWaiting called with the number of running jobs, once before waiting and again
	 *                                        whenever that number changes
	 * @throws \RuntimeException when they do not end in time
	 */
	public function waitForRunningJobs(?array $providerKeys = null, ?callable $onWaiting = null): void {
		$prefixes = $providerKeys === null
			? [self::RUNNING_KEY_PREFIX]
			: array_map(fn (string $key): string => self::RUNNING_KEY_PREFIX . $this->getProviderConfigPartKey($key) . '_', $providerKeys);
		$this->waitUntilNone(
			fn (): int => $this->countRunningJobs($prefixes),
			$onWaiting,
			'background jobs are still queueing documents',
			'If they were killed, their records expire within an hour, or use --ignore-handed-out.',
		);
	}

	/**
	 * Waits for the documents the backend took to be reported back. A document that is still being indexed
	 * would be written to the vector DB after it was wiped.
	 *
	 * @param list<string>|null $providerKeys only wait for the documents of these providers, null for all of them
	 * @param ?callable(int): void $onWaiting called with the number of documents still being indexed,
	 *                                        once before waiting and again whenever that number changes
	 * @throws \RuntimeException when they are not reported back in time
	 * @throws \OCP\DB\Exception
	 */
	public function waitForHandedOutDocuments(?array $providerKeys = null, ?callable $onWaiting = null): void {
		$this->waitUntilNone(
			fn (): int => $this->countHandedOutDocuments($providerKeys),
			$onWaiting,
			'documents are still being indexed by context_chat_backend',
			'If the backend was stopped while indexing them, they would only be released after a day, use --ignore-handed-out then.',
		);
	}

	/**
	 * @param callable(): int $count what is left to wait for
	 * @param ?callable(int): void $onWaiting called with what is left, once before waiting and again whenever it changes
	 * @param string $progressInfoText what is being awaited, for the error message
	 * @param string $hint what to do if it never ends, for the error message
	 * @throws \RuntimeException when it is not done in time, receives the above error message
	 */
	private function waitUntilNone(callable $count, ?callable $onWaiting, string $progressInfoText, string $hint): void {
		$timeout = $this->getDrainTimeout();
		$deadline = $this->timeFactory->getTime() + $timeout;
		$reported = null;
		while (($left = $count()) > 0) {
			if ($onWaiting !== null && $left !== $reported) {
				$onWaiting($left);
				$reported = $left;
			}
			if ($this->timeFactory->getTime() >= $deadline) {
				throw new \RuntimeException(
					$left . ' ' . $progressInfoText . ' after waiting ' . $timeout . ' seconds. Try again later. ' . $hint
				);
			}
			sleep(1);
		}
	}

	private function getDrainTimeout(): int {
		return $this->appConfig->getAppValueInt('wipe_drain_timeout', self::DEFAULT_DRAIN_TIMEOUT, lazy: true);
	}

	private function readUntil(string $key): int {
		return (int)($this->readValues($key)[$key] ?? 0);
	}

	/**
	 * Read from the database instead of IAppConfig. IAppConfig values are cached by each web server
	 * for a few seconds, and all of them must stop handing out items before the wipe starts.
	 * This ensures that we get the most up-to-date value for the pause state.
	 *
	 * @return array<string, string> configkey: value
	 */
	private function readValues(string $key, bool $isPrefix = false): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('configkey', 'configvalue')
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)));
		if ($isPrefix) {
			$qb->andWhere($qb->expr()->like('configkey', $qb->createNamedParameter($this->db->escapeLikeParameter($key) . '%')));
		} else {
			$qb->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)));
		}
		$result = $qb->executeQuery();
		$values = [];
		while (($row = $result->fetch()) !== false) {
			$values[(string)$row['configkey']] = (string)$row['configvalue'];
		}
		$result->closeCursor();
		return $values;
	}
}
