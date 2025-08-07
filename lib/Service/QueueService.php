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
		$this->scheduleJob($file);
	}

	/**
	 * @param QueueFile $file
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
		$nonUpdates = $this->queueMapper->getFromQueue($storageId, $rootId, $batchSize, true);
		if (empty($nonUpdates)) {
			return $this->queueMapper->getFromQueue($storageId, $rootId, $batchSize, false);
		}

		return $nonUpdates;
	}

	public function existsQueueFileId(int $fileId): bool {
		$queueItem = new QueueFile();
		$queueItem->setFileId($fileId);
		return $this->queueMapper->existsQueueItem($queueItem);
	}

	/**
	 * @param QueueFile[] $files
	 * @return void
	 * @throws \OCP\DB\Exception
	 */
	public function removeFromQueue(array $files): void {
		$this->queueMapper->removeFromQueue($files);
	}

	/**
	 * @throws Exception
	 */
	public function clearQueue(): void {
		$this->queueMapper->clearQueue();
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
