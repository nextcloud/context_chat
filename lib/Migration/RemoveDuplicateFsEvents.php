<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Migration;

use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

class RemoveDuplicateFsEvents implements IRepairStep {

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function getName(): string {
		return 'Remove duplicate FS events';
	}

	#[\Override]
	public function run(IOutput $output): void {
		try {
			$this->db->beginTransaction();
			// Get the lowest ID for each combination of type, user_id, and node_id
			$subQuery = $this->db->getQueryBuilder();
			$subQuery->selectAlias($subQuery->func()->min('id'), 'id')
				->from('context_chat_fs_events')
				->groupBy('type', 'user_id', 'node_id');

			if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL) {
				// MySQL does not allow the table you're deleting from be used in a subquery for the condition.
				// (We can't use the same table (e) in a DELETE and in its sub-SELECT. We can, however use a sub-sub-SELECT to create a temporary table (x), and use that for the sub-SELECT.)
				// See https://stackoverflow.com/questions/4471277/mysql-delete-from-with-subquery-as-condition
				$secondSubQuery = $this->db->getQueryBuilder();
				$secondSubQuery->select('id')->from($secondSubQuery->createFunction('(' . $subQuery->getSQL() . ')'), 'x');
				$sql = $secondSubQuery->getSQL();
			} else {
				$sql = $subQuery->getSQL();
			}

			// Delete all rows where the ID is not in the subquery result
			$qb = $this->db->getQueryBuilder();
			$qb->delete('context_chat_fs_events')
				->where($qb->expr()->notIn('id', $qb->createFunction('(' . $sql . ')')));

			$qb->executeStatement();
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			$output->warning('Failed to automatically remove duplicate fs events for context_chat.');
			$this->logger->error('Failed to automatically remove duplicate fs events for context_chat', ['exception' => $e]);
		}
	}
}
