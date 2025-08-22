<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Db;

use OCA\ContextChat\Logger;
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
	protected const DELETE_BATCH_SIZE = 1000;

	public function __construct(
		IDBConnection $db,
		private Logger $logger,
	) {
		parent::__construct($db, 'context_chat_fs_events', FsEvent::class);
	}

	public function insertRow(string $type, string $userId, int $nodeId): Entity {
		try {
			$this->db->beginTransaction();
			$qb = $this->db->getQueryBuilder();
			$qb->select('*')
				->from($this->getTableName())
				->setMaxResults(1)
				->where(
					$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
					$qb->expr()->eq('type', $qb->createNamedParameter($type)),
					$qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)),
				);
			$entities = $this->findEntities($qb);

			if (!empty($entities)) {
				return $entities[0];
			}

			$entity = new FsEvent();
			$entity->setUserId($userId);
			$entity->setType($type);
			$entity->setNodeId($nodeId);

			$insertedEntry = $this->insert($entity);
			$this->db->commit();
			return $insertedEntry;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * @param int $limit
	 * @return array<FsEvent>
	 * @throws \OCP\DB\Exception
	 */
	public function getFromQueue(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct(['user_id', 'type', 'node_id'])
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
	public function deleteByContent(string $type, string $ownerId, int $nodeId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($ownerId)))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
			->andWhere($qb->expr()->eq('node_id', $qb->createNamedParameter($nodeId, IQueryBuilder::PARAM_INT)))
		;

		return $qb->executeStatement();
	}

	/**
	 * @throws Exception
	 */
	public function deleteAllMatches(FsEvent $entity, int $triesLeft = 1): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($entity->getUserId())))
			->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($entity->getType())))
			->andWhere($qb->expr()->eq('node_id', $qb->createNamedParameter($entity->getNodeId(), IQueryBuilder::PARAM_INT)))
			->setMaxResults(self::DELETE_BATCH_SIZE)
		;

		$totalDeleted = 0;
		try {
			do {
				$this->db->beginTransaction();
				$deletedCount = $qb->executeStatement();
				$totalDeleted += $deletedCount;
				$this->db->commit();
			} while ($deletedCount > 0);
			$this->logger->info('Deleted fs events and duplicates', [
				'type' => $entity->getType(),
				'userId' => $entity->getUserId(),
				'nodeId' => $entity->getNodeId(),
				'totalDeleted' => $totalDeleted,
			]);
		} catch (\Throwable $e) {
			$this->db->rollBack();
			$this->logger->error('Error deleting fs events and duplicates'
				. $triesLeft <= 0 ? ', no tries left' : ', retrying...', [
					'exception' => $e,
					'type' => $entity->getType(),
					'userId' => $entity->getUserId(),
					'nodeId' => $entity->getNodeId(),
					'triesLeft' => $triesLeft,
					'totalDeleted in this try' => $totalDeleted,
				]);
			if ($triesLeft <= 0) {
				throw $e;
			}
			$this->deleteAllMatches($entity, $triesLeft - 1);
		}
	}
}
