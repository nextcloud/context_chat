<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Db;

use OCA\ContextChat\Service\ProviderConfigService;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<QueueContentItem>
 */
class QueueContentItemMapper extends QBMapper {
	public const LOCK_TIMEOUT = 60 * 60 * 24;

	/**
	 * @var IDBConnection $db
	 */
	protected $db;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'context_chat_content_queue', QueueContentItem::class);
	}

	/**
	 * @param int $limit
	 * @return array<QueueContentItem>
	 * @throws \OCP\DB\Exception
	 */
	public function getFromQueue(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(QueueContentItem::$columns)
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
	 * @throws \OCP\DB\Exception
	 * @return array<string, int>
	 */
	public function count() : array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->count('id', 'count'), 'app_id', 'provider_id')
			->from($this->getTableName())
			->groupBy('app_id', 'provider_id')
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
		$stats = [];
		while (($row = $result->fetch()) !== false) {
			$provider = ProviderConfigService::getConfigKey($row['app_id'], $row['provider_id']);
			$stats[$provider] = $row['count'];
		}
		return $stats;
	}

	public function countLocked() : array {
		$qb = $this->db->getQueryBuilder();
		$result = $qb->select($qb->func()->count('id', 'count'), 'app_id', 'provider_id')
			->from($this->getTableName())
			->groupBy('app_id', 'provider_id')
			->where(
				$qb->expr()->gt(
					'locked_at',
					$qb->createPositionalParameter(
						(new \DateTime())->sub(new \DateInterval('PT' . self::LOCK_TIMEOUT . 'S')),
						IQueryBuilder::PARAM_DATETIME_MUTABLE
					)
				)
			)
			->executeQuery();
		$stats = [];
		while (($row = $result->fetch()) !== false) {
			$provider = ProviderConfigService::getConfigKey($row['app_id'], $row['provider_id']);
			$stats[$provider] = $row['count'];
		}
		return $stats;
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
