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
 * @template-extends QBMapper<FsEvent>
 */
class FsEventMapper extends QBMapper {
	/**
	 * @var IDBConnection $db
	 */
	protected $db;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'context_chat_fs_events', FsEvent::class);
	}

	/**
	 * @param int $limit
	 * @return array<FsEvent>
	 * @throws \OCP\DB\Exception
	 */
	public function getFromQueue(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(FsEvent::$columns)
			->from($this->getTableName())
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function count() : int {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->count('id'))
			->from($this->getTableName())
			->executeQuery();
		$cnt = $result->fetchOne();
		if ($cnt !== false) {
			return (int)$cnt;
		}
		return 0;
	}

	/**
	 * @throws Exception
	 */
	public function deleteByContent(\OCA\ContextChat\Type\FsEventType $type, string $ownerId, int $nodeId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('type', $qb->createNamedParameter($type->value)))
			->andWhere($qb->expr()->eq('userId', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('nodeId', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)))
		;

		return $qb->executeStatement();
	}
}
