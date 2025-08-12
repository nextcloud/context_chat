<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Db;

use OCP\AppFramework\Db\Entity;
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

	#[\Override]
	public function insert(Entity $entity): Entity {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->setMaxResults(1)
			->where(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($entity->getUserId())),
				$qb->expr()->eq('node_id', $qb->createNamedParameter($entity->getNodeId(), IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('type', $qb->createNamedParameter($entity->getType()))
			);
		$entities = $this->findEntities($qb);
		if (empty($entities)) {
			return parent::insert($entity);
		}
		return $entities[0];
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
			->orderBy('id', 'ASC')
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
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)))
		;

		return $qb->executeStatement();
	}
}
