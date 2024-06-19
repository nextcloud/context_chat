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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<QueueDelete>
 */
class QueueDeleteMapper extends QBMapper {
	/**
	 * @var IDBConnection $db
	 */
	protected $db;

	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'context_chat_delete_queue', QueueDelete::class);
	}

	/**
	 * @param int $limit
	 * @return array<QueueDelete>
	 * @throws \OCP\DB\Exception
	 */
	public function getFromQueue(int $limit): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select(QueueDelete::$columns)
			->from($this->getTableName())
			->setMaxResults($limit);

		return $this->findEntities($qb);
	}

	/**
	 * @param QueueDelete $item
	 * @return void
	 * @throws \OCP\DB\Exception
	 */
	public function removeFromQueue(QueueDelete $item): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createPositionalParameter($item->getId())))
			->executeStatement();
	}

	/**
	 * @param QueueDelete $item
	 * @return void
	 * @throws \OCP\DB\Exception
	 */
	public function insertIntoQueue(QueueDelete $item): void {
		$qb = $this->db->getQueryBuilder();
		$qb->insert($this->getTableName())
			->values([
				'type' => $qb->createPositionalParameter($item->getType(), IQueryBuilder::PARAM_STR),
				'user_id' => $qb->createPositionalParameter($item->getUserId(), IQueryBuilder::PARAM_STR),
				'payload' => $qb->createPositionalParameter($item->getPayload(), IQueryBuilder::PARAM_STR),
			])
			->executeStatement();
	}
}
