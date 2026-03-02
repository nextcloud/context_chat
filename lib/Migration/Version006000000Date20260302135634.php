<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version006000000Date20260302135634 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('context_chat_action_queue')) {
			$table = $schema->getTable('context_chat_action_queue');
			$table->addColumn('locked_at', Types::DATETIME, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addIndex(['locked_at'], 'cc_action_queue_lock');
		}

		if ($schema->hasTable('context_chat_content_queue')) {
			$table = $schema->getTable('context_chat_content_queue');
			$table->addColumn('locked_at', Types::DATETIME, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addIndex(['locked_at'], 'cc_content_queue_lock');
		}

		if ($schema->hasTable('context_chat_queue')) {
			$table = $schema->getTable('context_chat_queue');
			$table->addColumn('locked_at', Types::DATETIME, [
				'notnull' => false,
				'default' => null,
			]);
			$table->addIndex(['locked_at'], 'cc_queue_lock');
		}

		return $schema;
	}
}
