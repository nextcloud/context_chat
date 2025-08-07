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
			$subQuery = $this->db->getQueryBuilder();
			$subQuery->selectAlias($subQuery->func()->min('id'), 'id')
				->from('context_chat_fs_events')
				->groupBy('type', 'user_id', 'node_id');

			if ($this->db->getDatabaseProvider() === IDBConnection::PLATFORM_MYSQL) {
				$secondSubQuery = $this->db->getQueryBuilder();
				$secondSubQuery->select('id')->from($secondSubQuery->createFunction('(' . $subQuery->getSQL() . ')'), 'x');
				$sql = $secondSubQuery->getSQL();
			} else {
				$sql = $subQuery->getSQL();
			}

			$qb = $this->db->getQueryBuilder();
			$qb->delete('context_chat_fs_events')
				->where($qb->expr()->notIn('id', $qb->createFunction('(' . $sql . ')')));

			$qb->executeStatement();
		} catch (\Throwable $e) {
			$output->warning('Failed to automatically remove duplicate fs events for context_chat.');
			$this->logger->error('Failed to automatically remove duplicate fs events for context_chat', ['exception' => $e]);
		}
	}
}
