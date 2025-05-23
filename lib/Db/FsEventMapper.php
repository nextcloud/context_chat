<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Db;

use OCP\AppFramework\Db\QBMapper;
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
		parent::__construct($db, 'context_chat_fs_events', QueueAction::class);
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
}
