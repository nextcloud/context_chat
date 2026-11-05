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
 * content provider does not pause, it clears the provider's queue before it waits for the handed out documents instead.
 *
 * A pause expires on its own after a day, so a wipe that dies half way does not stop the indexing forever.
 * Running the wipe again lifts it right away: it starts a new pause and lifts that one when it ends.
 */
class FetchGuard {
	private const ALL_KEY = 'queue_fetch_paused_until';
	private const FILES_KEY = 'files_fetch_paused_until';
	// pause indexing while wipe happens, max 1 day so a crashed process does not stop it indefinitely
	private const PAUSE_DURATION = 24 * 60 * 60;
	public const DEFAULT_DRAIN_TIMEOUT = 30 * 60;  // 30 mins for the currently indexing documents to be done

	public function __construct(
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private ITimeFactory $timeFactory,
		private QueueMapper $queueMapper,
		private QueueContentItemMapper $contentQueue,
	) {
	}

	/**
	 * @param bool $onlyFiles hold back the files only, for a wipe of the files
	 * @return array{0: string, 1: int} the pause, to be passed to resume()
	 */
	public function pause(bool $onlyFiles = false): array {
		$key = $onlyFiles ? self::FILES_KEY : self::ALL_KEY;
		$until = $this->timeFactory->getTime() + self::PAUSE_DURATION;
		$this->appConfig->setAppValueInt($key, $until, lazy: true);
		return [$key, $until];
	}

	/**
	 * Lifts the pause, unless another one of the same kind was started since.
	 *
	 * @param array{0: string, 1: int} $pause
	 */
	public function resume(array $pause): void {
		[$key, $until] = $pause;
		if ($this->readUntil($key) === $until) {
			$this->appConfig->deleteAppValue($key);
		}
	}

	/**
	 * Whether a full wipe is running: nothing is handed out, not even actions.
	 */
	public function isPaused(): bool {
		return $this->readUntil(self::ALL_KEY) > $this->timeFactory->getTime();
	}

	/**
	 * Whether the files are held back, by a full wipe or a wipe of the files.
	 */
	public function areFilesPaused(): bool {
		return $this->isPaused() || $this->readUntil(self::FILES_KEY) > $this->timeFactory->getTime();
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
		$deadline = $this->timeFactory->getTime() + $this->getDrainTimeout();
		$reported = null;
		while (($locked = $this->countHandedOutDocuments($providerKeys)) > 0) {
			if ($onWaiting !== null && $locked !== $reported) {
				$onWaiting($locked);
				$reported = $locked;
			}
			if ($this->timeFactory->getTime() >= $deadline) {
				throw new \RuntimeException(
					$locked . ' documents are still being indexed by context_chat_backend after waiting '
					. $this->getDrainTimeout() . ' seconds. Try again later. If the backend was stopped while'
					. ' indexing them, they would only be released after a day, use --ignore-handed-out then.'
				);
			}
			sleep(1);
		}
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

	private function getDrainTimeout(): int {
		return $this->appConfig->getAppValueInt('wipe_drain_timeout', self::DEFAULT_DRAIN_TIMEOUT, lazy: true);
	}

	/**
	 * Read from the database instead of IAppConfig. IAppConfig values are cached by each web server
	 * for a few seconds, and all of them must stop handing out items before the wipe starts.
	 * This ensures that we get the most up-to-date value for the pause state.
	 */
	private function readUntil(string $key): int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('configvalue')
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter($key)))
			->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return $value === false ? 0 : (int)$value;
	}
}
