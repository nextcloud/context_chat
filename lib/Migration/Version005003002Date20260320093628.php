<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

class Version005003002Date20260320093628 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[Override]
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('context_chat_content_queue')) {
			$output->info('Table context_chat_content_queue does not exist, skipping de-duplication.');
			return;
		}

		$totalRowsDeleted = 0;
		$qb = $this->db->getQueryBuilder();
		$qb->select('app_id', 'provider_id', 'item_id')
			->selectAlias($qb->func()->max('id'), 'keep_id')
			->from('context_chat_content_queue')
			->groupBy('app_id', 'provider_id', 'item_id')
			->having($qb->expr()->gt($qb->func()->count('*'), $qb->createNamedParameter(1)));
		$selectQuery = $qb->executeQuery();

		$qb2 = $this->db->getQueryBuilder();
		$qb2->delete('context_chat_content_queue')
			->where(
				$qb2->expr()->eq('app_id', $qb2->createParameter('appId')),
				$qb2->expr()->eq('provider_id', $qb2->createParameter('providerId')),
				$qb2->expr()->eq('item_id', $qb2->createParameter('itemId')),
				$qb2->expr()->neq('id', $qb2->createParameter('keepId')),
			);

		try {
			while ($row = $selectQuery->fetch()) {
				$qb2->setParameter('appId', $row['app_id'])
					->setParameter('providerId', $row['provider_id'])
					->setParameter('itemId', $row['item_id'])
					->setParameter('keepId', $row['keep_id']);

				$rowsDeleted = $qb2->executeStatement();
				$totalRowsDeleted += $rowsDeleted;
			}
		} catch (\Exception $e) {
			$selectQuery->closeCursor();
			throw $e;
		}

		$output->info("Removed $totalRowsDeleted duplicate content provider entries.");
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options) {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$schemaChanged = false;

		if ($schema->hasTable('context_chat_content_queue')) {
			$table = $schema->getTable('context_chat_content_queue');
			$table->addUniqueIndex(['app_id', 'provider_id', 'item_id'], 'ccc_q_provider');
			$schemaChanged = true;
		}

		return $schemaChanged ? $schema : null;
	}
}
