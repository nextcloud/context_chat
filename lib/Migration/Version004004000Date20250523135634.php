<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version004004000Date20250523135634 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('context_chat_fs_events')) {
			$table = $schema->createTable('context_chat_fs_events');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 64,
				'unsigned' => true,
			]);
			$table->addColumn('type', Types::STRING, [
				'notnull' => true,
				'length' => 128,
			]);
			$table->addColumn('user_id', Types::STRING, [
				'notnull' => true,
				'length' => 128,
			]);
			$table->addColumn('node_id', Types::BIGINT, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id'], 'cc_fs_events_id');
		}

		return $schema;
	}
}
