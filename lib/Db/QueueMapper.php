<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2022 The Recognize contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<QueueFile>
 */
class QueueMapper extends QBMapper {
	public const LOCK_TIMEOUT = 60 * 60 * 24;
	/**
	 * @var IDBConnection $db
	 */
	protected $db;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'context_chat_queue', QueueFile::class);
	}

	/**
	 * @param int $n
	 * @return list<QueueFile>
	 * @throws Exception
	 */
	public function getFromQueue(int $n) : array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(QueueFile::$columns)
			->from($this->getTableName())
			->andWhere($qb->expr()->orX(
				// Get queue items if they are not locked, or the lock is older than one day
				$qb->expr()->isNull('locked_at'),
				$qb->expr()->lte(
					'locked_at',
					$qb->createPositionalParameter(
						(new \DateTime())->sub(new \DateInterval('PT' . self::LOCK_TIMEOUT . 'S')),
						IQueryBuilder::PARAM_DATETIME_MUTABLE
					)
				)
			))
			->setMaxResults($n)
			->addOrderBy('id', 'ASC')
			->addOrderBy('update', 'ASC');
		return $this->findEntities($qb);
	}

	/**
	 * @param list<int> $ids
	 * @return void
	 * @throws \OCP\DB\Exception
	 */
	public function removeFromQueue(array $ids): void {
		$chunkSize = 1000; // Maximum number of items in an "IN" expression
		foreach (array_chunk($ids, $chunkSize) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('id', $qb->createPositionalParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}

	/**
	 * @param int $dbId
	 * @return bool
	 * @throws \OCP\DB\Exception
	 */
	public function existsQueueItemsUpToDbId(int $dbId) : bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->lte('id', $qb->createPositionalParameter($dbId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			$this->findEntity($qb);
			return true;
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception) {
			return false;
		}
	}

	/**
	 * @param int $fileId
	 * @return QueueFile|null
	 */
	public function findQueueItemByFileId(int $fileId) : ?QueueFile {
		return $this->internalFindQueueItemByFileId($fileId);
	}

	/**
	 * @param QueueFile $file
	 * @return QueueFile|null
	 */
	public function findQueueItem(QueueFile $file) : ?QueueFile {
		return $this->internalFindQueueItemByFileId($file->getFileId());
	}

	private function internalFindQueueItemByFileId(int $fileId): ?QueueFile {
		$qb = $this->db->getQueryBuilder();
		$qb->select(QueueFile::$columns)
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createPositionalParameter($fileId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException|MultipleObjectsReturnedException|Exception $e) {
			return null;
		}
	}

	/**
	 * @param QueueFile $file
	 * @return QueueFile
	 * @throws \OCP\DB\Exception
	 */
	public function insertIntoQueue(QueueFile $file) : QueueFile {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->values([
				'file_id' => $qb->createPositionalParameter($file->getFileId(), IQueryBuilder::PARAM_INT),
				'storage_id' => $qb->createPositionalParameter($file->getStorageId(), IQueryBuilder::PARAM_INT),
				'root_id' => $qb->createPositionalParameter($file->getRootId(), IQueryBuilder::PARAM_INT),
				'update' => $qb->createPositionalParameter($file->getUpdate(), IQueryBuilder::PARAM_BOOL)
			])
			->executeStatement();
		$file->setId($qb->getLastInsertId());
		return $file;
	}

	/**
	 * @throws Exception
	 */
	public function clearQueue(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->executeStatement();
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function count(bool $onlyNewFiles = false) : int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->andWhere($qb->expr()->orX(
				// Get queue items if they are not locked, or the lock is older than one day
				$qb->expr()->isNull('locked_at'),
				$qb->expr()->lte(
					'locked_at',
					$qb->createPositionalParameter(
						(new \DateTime())->sub(new \DateInterval('PT' . self::LOCK_TIMEOUT . 'S')),
						IQueryBuilder::PARAM_DATETIME_MUTABLE
					)
				)
			));
		if ($onlyNewFiles) {
			$qb->andWhere($qb->expr()->eq('update', $qb->createPositionalParameter(false, IQueryBuilder::PARAM_BOOL)));
		}
		$result = $qb->executeQuery();
		if (($cnt = $result->fetchOne()) !== false) {
			return (int)$cnt;
		}
		return 0;
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function countLocked(bool $withTimeout = true) : int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))
			->from($this->getTableName());
		if ($withTimeout) {
			$qb->andWhere(
				$qb->expr()->gt(
					'locked_at',
					$qb->createPositionalParameter(
						(new \DateTime())->sub(new \DateInterval('PT' . self::LOCK_TIMEOUT . 'S')),
						IQueryBuilder::PARAM_DATETIME_MUTABLE
					)
				)
			);
		}
		$result = $qb->executeQuery();
		if (($cnt = $result->fetchOne()) !== false) {
			return (int)$cnt;
		}
		return 0;
	}

	/**
	 * @throws Exception
	 */
	public function lock(int $id) : bool {
		// TODO: Add a retry column to count how many times an item has been locked again without being processed
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere(
				$qb->expr()->orX(
					$qb->expr()->isNull('locked_at'),
					$qb->expr()->lte('locked_at', $qb->createNamedParameter(
						(new \DateTime('now'))->sub(new \DateInterval('PT' . self::LOCK_TIMEOUT . 'S')),
						IQueryBuilder::PARAM_DATETIME_MUTABLE
					))
				)
			)
			->set('locked_at', $qb->createNamedParameter(new \DateTime('now'), IQueryBuilder::PARAM_DATETIME_MUTABLE));

		if ($qb->executeStatement() >= 1) {
			return true;
		}
		return false;
	}
}
