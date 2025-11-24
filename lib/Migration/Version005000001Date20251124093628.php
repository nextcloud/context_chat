<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Migration;

use Closure;
use OCA\ContextChat\AppInfo\Application;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

class Version005000001Date20251124093628 extends SimpleMigrationStep {
	private static array $configKeys = [
		'action_job_interval',
		'auto_indexing',
		'backend_init',
		'crawl_job_interval',
		'fs_listener_job_interval',
		'indexed_files_count',
		'indexing_batch_size',
		'indexing_job_interval',
		'indexing_max_size',
		'indexing_max_time',
		'installed_time',
		'last_indexed_file_id',
		'last_indexed_time',
		'logfile',
		'providers',
	];

	public function __construct(
		private IDBConnection $connection,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 * @param array $options
	 * @return null|ISchemaWrapper
	 */
	#[Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		$schema = $schemaClosure();

		if (!$schema->hasTable('appconfig')) {
			return null;
		}

		$qb = $this->connection->getQueryBuilder();
		$qb->update('appconfig')
			->set('lazy', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->where(
				$qb->expr()->eq('appid', $qb->createNamedParameter(Application::APP_ID, IQueryBuilder::PARAM_STR))
			)
			->andWhere(
				$qb->expr()->in('configkey', $qb->createNamedParameter(self::$configKeys, IQueryBuilder::PARAM_STR_ARRAY))
			);
		$qb->executeStatement();

		return $schema;
	}
}
