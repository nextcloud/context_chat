<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022 The Recognize contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\BackgroundJobs\IndexerJob;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Db\QueueMapper;
use OCP\BackgroundJob\IJobList;
use OCP\DB\Exception;

class QueueService {

	public function __construct(
		private QueueMapper $queueMapper,
		private IJobList $jobList,
	) {
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function insertIntoQueue(QueueFile $file): void {
		// Only add to queue if it's not in there already
		if ($this->queueMapper->existsQueueItem($file)) {
			return;
		}

		$this->queueMapper->insertIntoQueue($file);
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
}
