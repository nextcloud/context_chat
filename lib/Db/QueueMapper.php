<?php
/*
 * Copyright (c) 2022 The Recognize contributors.
 * Copyright (c) 2023 Marcel Klehr <mklehr@gmx.net>
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
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
	 * @param int $n
	 * @return list<QueueFile>
	 * @throws \OCP\DB\Exception
	 */
	public function getFromQueue(int $storageId, int $rootId, int $n) : array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(QueueFile::$columns)
			->from($this->getTableName())
			->where($qb->expr()->eq('storage_id', $qb->createPositionalParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('root_id', $qb->createPositionalParameter($rootId, IQueryBuilder::PARAM_INT)))
			->setMaxResults($n);

		return $this->findEntities($qb);
	}

	/**
	 * @param QueueFile $file
	 * @return void
	 * @throws \OCP\DB\Exception
	 */
	public function removeFromQueue(QueueFile $file) : void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createPositionalParameter($file->getId())))
			->executeStatement();
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

	public function clearQueue(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->executeStatement();
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function count() : int|false {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->executeQuery();
		return $result->fetchOne();
	}
}
