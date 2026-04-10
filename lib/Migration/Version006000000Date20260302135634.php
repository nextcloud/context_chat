<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Migration;

use Closure;
use OCA\ContextChat\Db\QueueMapper;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version006000000Date20260302135634 extends SimpleMigrationStep {
	public function __construct(
		private IAppConfig $appConfig,
		private QueueMapper $queueMapper,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$schemaChanged = false;

		if ($schema->hasTable('context_chat_action_queue')) {
			$table = $schema->getTable('context_chat_action_queue');
			if (!$table->hasColumn('locked_at')) {
				$table->addColumn('locked_at', Types::DATETIME, [
					'notnull' => false,
					'default' => null,
				]);
				$table->addIndex(['locked_at'], 'cc_action_queue_lock');
				$schemaChanged = true;
			}
		}

		if ($schema->hasTable('context_chat_content_queue')) {
			$table = $schema->getTable('context_chat_content_queue');
			if (!$table->hasColumn('locked_at')) {
				$table->addColumn('locked_at', Types::DATETIME, [
					'notnull' => false,
					'default' => null,
				]);
				$table->addIndex(['locked_at'], 'cc_content_queue_lock');
				$schemaChanged = true;
			}
		}

		if ($schema->hasTable('context_chat_queue')) {
			$table = $schema->getTable('context_chat_queue');
			if (!$table->hasColumn('locked_at')) {
				$table->addColumn('locked_at', Types::DATETIME, [
					'notnull' => false,
					'default' => null,
				]);
				$table->addIndex(['locked_at'], 'cc_queue_lock');
				$schemaChanged = true;
			}
		}

		return $schemaChanged ? $schema : null;
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		if (($configVal = $this->appConfig->getAppValueInt('last_indexed_file_id', -1, lazy: true)) === -1) {
			return;
		}
		$this->appConfig->deleteAppValue('last_indexed_file_id');

		if (($queueFile = $this->queueMapper->findQueueItemByFileId($configVal)) === null) {
			return;
		}
		$this->appConfig->setAppValueInt('last_enqueued_db_id', $queueFile->getId(), lazy: true);
	}
}
