<?php
/*
 * Copyright (c) 2022 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);

namespace OCA\ContextChat\Service;

use OCA\ContextChat\BackgroundJobs\IndexerJob;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Db\QueueMapper;
use OCP\BackgroundJob\IJobList;

class QueueService {

	public function __construct(
		private QueueMapper $queueMapper,
		private IJobList    $jobList) {
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
		$this->scheduleJob($file);
	}

	/**
	 * @param string $model
	 * @param QueueFile $file
	 * @param string|null $userId
	 * @return void
	 */
	public function scheduleJob(QueueFile $file): void {
		if (!$this->jobList->has(IndexerJob::class, [
			'storageId' => $file->getStorageId(),
			'rootId' => $file->getRootId(),
		])) {
			$this->jobList->add(IndexerJob::class, [
				'storageId' => $file->getStorageId(),
				'rootId' => $file->getRootId(),
			]);
		}
	}

	/**
	 * @param int $storageId
	 * @param int $rootId
	 * @param int $batchSize
	 * @return QueueFile[]
	 * @throws \OCP\DB\Exception
	 */
	public function getFromQueue(int $storageId, int $rootId, int $batchSize): array {
		return $this->queueMapper->getFromQueue($storageId, $rootId, $batchSize);
	}

	/**
	 * @param string $model
	 * @param QueueFile $queueFile
	 * @return void
	 * @throws \OCP\DB\Exception
	 */
	public function removeFromQueue(QueueFile $queueFile): void {
		$this->queueMapper->removeFromQueue($queueFile);
	}

	public function clearQueue(): void {
		$this->queueMapper->clearQueue();
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function count(): int|false {
		return $this->queueMapper->count();
	}
}
