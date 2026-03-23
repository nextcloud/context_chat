<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Db;

use OCA\ContextChat\Service\ProviderConfigService;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<QueueContentItem>
 */
class QueueContentItemMapper extends QBMapper {
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
			->orderBy('id', 'ASC')
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * @param QueueContentItem $item
	 * @return void
	 * @throws \OCP\DB\Exception
	 */
	public function removeFromQueue(QueueContentItem $item): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createPositionalParameter($item->getId())))
			->executeStatement();
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
			->executeQuery();
		$stats = [];
		while (($row = $result->fetch()) !== false) {
			$provider = ProviderConfigService::getConfigKey($row['app_id'], $row['provider_id']);
			$stats[$provider] = $row['count'];
		}
		return $stats;
	}
}
