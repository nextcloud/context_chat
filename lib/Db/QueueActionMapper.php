<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<QueueAction>
 */
class QueueActionMapper extends QBMapper {
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
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * @param QueueAction $item
	 * @return void
	 * @throws \OCP\DB\Exception
	 */
	public function removeFromQueue(QueueAction $item): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createPositionalParameter($item->getId())))
			->executeStatement();
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
			->executeQuery();
		if (($cnt = $result->fetchOne()) !== false) {
			return (int)$cnt;
		}
		return 0;
	}
}
