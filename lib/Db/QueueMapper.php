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
	/**
	 * @var IDBConnection $db
	 */
	protected $db;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'context_chat_queue', QueueFile::class);
	}

	/**
	 * @param int $storageId
	 * @param int $rootId
	 * @param int $n
	 * @param bool $onlyNewFiles
	 * @return list<QueueFile>
	 * @throws Exception
	 */
	public function getFromQueue(int $storageId, int $rootId, int $n, bool $onlyNewFiles = false) : array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(QueueFile::$columns)
			->from($this->getTableName())
			->where($qb->expr()->eq('storage_id', $qb->createPositionalParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('root_id', $qb->createPositionalParameter($rootId, IQueryBuilder::PARAM_INT)))
			->setMaxResults($n)
			->orderBy('id', 'ASC');

		if ($onlyNewFiles) {
			$qb->andWhere($qb->expr()->eq('update', $qb->createPositionalParameter(false, IQueryBuilder::PARAM_BOOL)));
		}

		return $this->findEntities($qb);
	}

	/**
	 * @param QueueFile[] $files
	 * @return void
	 * @throws \OCP\DB\Exception
	 */
	public function removeFromQueue(array $files): void {
		$ids = array_map(fn (QueueFile $file) => $file->getId(), $files);
		$chunkSize = 1000; // Maximum number of items in an "IN" expression
		foreach (array_chunk($ids, $chunkSize) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->delete($this->getTableName())
				->where($qb->expr()->in('id', $qb->createPositionalParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->executeStatement();
		}
	}


	/**
	 * @param QueueFile $file
	 * @return bool
	 */
	public function existsQueueItem(QueueFile $file) : bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select(QueueFile::$columns)
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createPositionalParameter($file->getFileId(), IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		try {
			$this->findEntity($qb);
			return true;
		} catch (DoesNotExistException $e) {
			return false;
		} catch (MultipleObjectsReturnedException $e) {
			return false;
		} catch (Exception $e) {
			return false;
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
			->from($this->getTableName());
		if ($onlyNewFiles) {
			$qb->andWhere($qb->expr()->eq('update', $qb->createPositionalParameter(false, IQueryBuilder::PARAM_BOOL)));
		}
		$result = $qb->executeQuery();
		if (($cnt = $result->fetchOne()) !== false) {
			return (int)$cnt;
		}
		return 0;
	}
}
