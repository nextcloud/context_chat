<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\Logger;
use OCP\AppFramework\Services\IAppConfig;
use OCP\Util;

class DiagnosticService {

	public function __construct(
		private IAppConfig $appConfig,
		private Logger $logger,
	) {
	}

	/**
	 * @param string $class
	 * @param int $id
	 * @return void
	 */
	public function sendJobTrigger(string $class, int $id): void {
		$this->logger->info('Background jobs: ' . $class . ' ' . $id . ' triggered');
	}

	/**
	 * @param string $class
	 * @param int $id
	 * @return void
	 */
	public function sendJobStart(string $class, int $id): void {
		$this->logger->info('Background jobs: ' . $class . ' ' . $id . ' started');
	}

	/**
	 * @param string $class
	 * @param int $id
	 * @return void
	 */
	public function sendJobEnd(string $class, int $id): void {
		$this->logger->info('Background jobs: ' . $class . ' ' . $id . ' ended');
	}

	/**
	 * @param string $class
	 * @param int $id
	 * @return void
	 */
	public function sendHeartbeat(string $class, int $id): void {
	}

	/**
	 * @param int $count
	 * @return void
	 */
	public function sendIndexedFiles(int $count): void {
		$this->logger->info('Indexed ' . $count . ' files');
		// We use numericToNumber to fall back to float in case int is too small
		$this->appConfig->setAppValueString(
			'indexed_files_count',
			(string)Util::numericToNumber(
				floatval($count) + floatval(Util::numericToNumber($this->appConfig->getAppValueString('indexed_files_count', '0', false)))
			),
			false,
		);
	}
}
