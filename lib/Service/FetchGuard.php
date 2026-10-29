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
 * A pause expires on its own, so a wipe that dies half way does not stop the indexing forever.
 */
class FetchGuard {
	private const CONFIG_KEY = 'queue_fetch_paused_until';
	// on top of the longest a wipe request to the backend can take
	private const MARGIN = 5 * 60;
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
	 * @return int the expiry of the pause, to be passed to resume()
	 */
	public function pause(): int {
		$until = $this->timeFactory->getTime() + $this->getDrainTimeout() + $this->getRequestTimeout() + self::MARGIN;
		$this->appConfig->setAppValueInt(self::CONFIG_KEY, $until, lazy: true);
		return $until;
	}

	/**
	 * Lifts the pause, unless another one was started since.
	 */
	public function resume(int $until): void {
		if ($this->getPausedUntil() === $until) {
			$this->appConfig->deleteAppValue(self::CONFIG_KEY);
		}
	}

	public function isPaused(): bool {
		return $this->getPausedUntil() > $this->timeFactory->getTime();
	}

	/**
	 * Waits for the documents the backend took before the pause to be reported back. A document that
	 * is still being indexed would be written to the vector DB after it was wiped.
	 *
	 * @param ?callable(int): void $onWaiting called with the number of documents still being indexed,
	 *                                        once before waiting and again whenever that number changes
	 * @throws \RuntimeException when they are not reported back in time
	 * @throws \OCP\DB\Exception
	 */
	public function waitForHandedOutDocuments(?callable $onWaiting = null): void {
		$deadline = $this->timeFactory->getTime() + $this->getDrainTimeout();
		$reported = null;
		while (($locked = $this->countHandedOutDocuments()) > 0) {
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
	 * @throws \OCP\DB\Exception
	 */
	private function countHandedOutDocuments(): int {
		return $this->queueMapper->countLocked() + intval(array_sum($this->contentQueue->countLocked()));
	}

	private function getDrainTimeout(): int {
		return $this->appConfig->getAppValueInt('wipe_drain_timeout', self::DEFAULT_DRAIN_TIMEOUT, lazy: true);
	}

	private function getRequestTimeout(): int {
		return (int)$this->appConfig->getAppValueString(
			'request_timeout',
			strval(Application::CC_DEFAULT_REQUEST_TIMEOUT),
			lazy: true,
		);
	}

	/**
	 * Read from the database instead of IAppConfig. IAppConfig values are cached by each web server
	 * for a few seconds, and all of them must stop handing out items before the wipe starts.
	 * This ensures that we get the most up-to-date value for the pause state.
	 */
	private function getPausedUntil(): int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select('configvalue')
			->from('appconfig')
			->where($qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID)))
			->andWhere($qb->expr()->eq('configkey', $qb->createNamedParameter(self::CONFIG_KEY)))
			->executeQuery();
		$value = $result->fetchOne();
		$result->closeCursor();
		return $value === false ? 0 : (int)$value;
	}
}
