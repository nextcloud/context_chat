<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<QueueAction>
 */
class QueueActionMapper extends QBMapper {
	public const LOCK_TIMEOUT = 60 * 60 * 24;
	/**
	 * @var IDBConnection $db
	 */
	protected $db;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'context_chat_action_queue', QueueAction::class);
	}

	/**
	 * @param int $limit
	 * @return array<QueueAction>
	 * @throws \OCP\DB\Exception
	 */
	public function getFromQueue(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(QueueAction::$columns)
			->from($this->getTableName())
			->where($qb->expr()->orX(
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
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

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
	 * @param QueueAction $item
	 * @return void
	 * @throws \OCP\DB\Exception
	 */
	public function insertIntoQueue(QueueAction $item): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->values([
				'type' => $qb->createPositionalParameter($item->getType(), IQueryBuilder::PARAM_STR),
				'payload' => $qb->createPositionalParameter($item->getPayload(), IQueryBuilder::PARAM_STR),
			])
			->executeStatement();
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function count() : int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->count('id'))
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
			->executeQuery();
		if (($cnt = $result->fetchOne()) !== false) {
			return (int)$cnt;
		}
		return 0;
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function countLocked() : int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->where($qb->expr()->gt(
				'locked_at',
				$qb->createPositionalParameter(
					(new \DateTime())->sub(new \DateInterval('PT' . self::LOCK_TIMEOUT . 'S')),
					IQueryBuilder::PARAM_DATETIME_MUTABLE
				)
			))
			->executeQuery();
		if (($cnt = $result->fetchOne()) !== false) {
			return (int)$cnt;
		}
		return 0;
	}

	/**
	 * @throws Exception
	 */
	public function lock(int $id) : bool {
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
