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

class Version1001Date20240130120627 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('context_chat_content_queue')) {
			$table = $schema->createTable('context_chat_content_queue');

			$table->addColumn('id', Types::BIGINT, [
				'autoincrement' => true,
				'notnull' => true,
				'length' => 64,
				'unsigned' => true,
			]);
			$table->addColumn('item_id', Types::STRING, [
				'notnull' => true,
				'length' => 512,
			]);
			$table->addColumn('app_id', Types::STRING, [
				'notnull' => true,
				'length' => 512,
			]);
			$table->addColumn('provider_id', Types::STRING, [
				'notnull' => true,
				'length' => 512,
			]);
			$table->addColumn('title', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('content', Types::TEXT, [
				'notnull' => true,
			]);
			$table->addColumn('document_type', Types::STRING, [
				'notnull' => true,
				'length' => 512,
			]);
			$table->addColumn('last_modified', Types::DATETIME, [
				'notnull' => true,
			]);
			$table->addColumn('users', Types::TEXT, [
				'notnull' => true,
			]);

			$table->setPrimaryKey(['id'], 'ccc_queue_id');
		}

		return $schema;
	}
}
