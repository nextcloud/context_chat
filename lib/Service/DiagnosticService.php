<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\Logger;
use OCP\AppFramework\Services\IAppConfig;

class DiagnosticService {

	public function __construct(
		private IAppConfig $appConfig,
		private Logger $logger,
	) {
	}

	/**
	 * @param string $class
	 * @param int|string $id
	 * @return void
	 */
	public function sendJobTrigger(string $class, int|string $id): void {
		$this->logger->info('Background jobs: ' . $class . ' ' . $id . ' triggered');
	}

	/**
	 * @param string $class
	 * @param int|string $id
	 * @return void
	 */
	public function sendJobStart(string $class, int|string $id): void {
		$this->logger->info('Background jobs: ' . $class . ' ' . $id . ' started');
	}

	/**
	 * @param string $class
	 * @param int|string $id
	 * @return void
	 */
	public function sendJobEnd(string $class, int|string $id): void {
		$this->logger->info('Background jobs: ' . $class . ' ' . $id . ' ended');
	}

	/**
	 * @param string $class
	 * @param int|string $id
	 * @return void
	 */
	public function sendHeartbeat(string $class, int|string $id): void {
	}
}
