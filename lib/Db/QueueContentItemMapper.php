<?php
/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Anupam Kumar <kyteinsky@gmail.com>
 * @copyright Anupam Kumar 2024
 */

declare(strict_types=1);

namespace OCA\ContextChat\Db;

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
}
