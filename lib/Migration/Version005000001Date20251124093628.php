<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Migration;

use Closure;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use Override;

class Version005000001Date20251124093628 extends SimpleMigrationStep {
	private static array $configKeys = [
		'int' => [
			'crawl_job_interval',
			'indexing_batch_size',
			'indexing_job_interval',
			'indexing_max_size',
			'indexing_max_time',
			'installed_time',
			'last_indexed_file_id',
			'last_indexed_time'
		],
		'string' => [
			'action_job_interval',
			'auto_indexing',
			'backend_init',
			'fs_listener_job_interval',
			'indexed_files_count',
			'logfile',
			'providers'
		]
	];

	public function __construct(
		private IAppConfig $appConfig,
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
		$allSetKeys = $this->appConfig->getAppKeys();

		foreach (self::$configKeys['int'] as $key) {
			// skip if not already set
			if (!in_array($key, $allSetKeys)) {
				continue;
			}
			$value = $this->appConfig->getAppValueInt($key);
			$this->appConfig->setAppValueInt($key, $value, lazy: true);
		}

		foreach (self::$configKeys['string'] as $key) {
			// skip if not already set
			if (!in_array($key, $allSetKeys)) {
				continue;
			}
			$value = $this->appConfig->getAppValueString($key);
			$this->appConfig->setAppValueString($key, $value, lazy: true);
		}

		return null;
	}
}
