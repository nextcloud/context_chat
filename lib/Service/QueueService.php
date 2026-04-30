<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 The Recognize contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Db\QueueMapper;
use OCP\DB\Exception;

class QueueService {

	public function __construct(
		private QueueMapper $queueMapper,
	) {
	}

	/**
	 * @throws \OCP\DB\Exception
	 * @return QueueFile
	 */
	public function insertIntoQueue(QueueFile $file): QueueFile {
		// Only add to queue if it's not in there already
		if ($dbFile = $this->queueMapper->findQueueItem($file)) {
			return $dbFile;
		}

		return $this->queueMapper->insertIntoQueue($file);
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function count(): int {
		return $this->queueMapper->count();
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function countNewFiles(): int {
		return $this->queueMapper->count(true);
	}

	/**
	 * @throws Exception
	 */
	public function countLocked(): int {
		return $this->queueMapper->countLocked();
	}
}
