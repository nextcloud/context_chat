<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

class Version005003002Date20260320093626 extends SimpleMigrationStep {
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
			$appIdCol = $table->getColumn('app_id');
			if ($appIdCol->getLength() !== 32) {
				$appIdCol->setLength(32);
				$schemaChanged = true;
			}

			$providerIdCol = $table->getColumn('provider_id');
			if ($providerIdCol->getLength() !== 63) {
				$providerIdCol->setLength(63);
				$schemaChanged = true;
			}

			$itemIdCol = $table->getColumn('item_id');
			if ($itemIdCol->getLength() !== 63) {
				$itemIdCol->setLength(63);
				$schemaChanged = true;
			}
		}

		return $schemaChanged ? $schema : null;
	}
}
