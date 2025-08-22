<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

class Version004004002Date20250821125112 extends SimpleMigrationStep {
	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$schemaChanged = false;

		// the index is to check for existing events before inserting a new one
		// user_id is the first so grouping based on user_id is faster
		if ($schema->hasTable('context_chat_fs_events')) {
			$table = $schema->getTable('context_chat_fs_events');
			if (!$table->hasIndex('cc_fs_events_full_idx')) {
				$table->addIndex(['user_id', 'type', 'node_id'], 'cc_fs_events_full_idx');
				$schemaChanged = true;
			}
		}

		return $schemaChanged ? $schema : null;
	}
}
