<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCP\Exceptions\AppConfigTypeConflictException;
use OCP\Util;
use Psr\Log\LoggerInterface;

class DiagnosticService {

	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array
	 * @throws AppConfigTypeConflictException
	 */
	public function getBackgroundJobDiagnostics(): array {
		return $this->appConfig->getAppValueArray('background_jobs_diagnostics', [], true);
	}

	/**
	 * @param array $value
	 * @return void
	 * @throws AppConfigTypeConflictException
	 * @throws \JsonException
	 */
	private function setBackgroundJobDiagnostics(array $value): void {
		$this->appConfig->setAppValueArray('background_jobs_diagnostics', $value, true);
	}

	/**
	 * @param string $class
	 * @param int $id
	 * @return void
	 */
	public function sendJobTrigger(string $class, int $id): void {
		$this->logger->info('CONTEXT_CHAT_DIAGNOSTICS: ' . $class . ' ' . $id . ' triggered');
		$key = $class . '-' . $id;
		try {
			$diagnostics = $this->getBackgroundJobDiagnostics();
			if (!isset($diagnostics[$key])) {
				$diagnostics[$key] = [];
			}
			$diagnostics[$key] = array_merge(['last_triggered' => time()], $diagnostics[$key]);
			$this->setBackgroundJobDiagnostics($diagnostics);
		} catch (\OCP\Exceptions\AppConfigTypeConflictException|\JsonException  $e) {
			$this->logger->warning('Error during context chat diagnostic jobStart', ['exception' => $e]);
		}
	}

	/**
	 * @param string $class
	 * @param int $id
	 * @return void
	 */
	public function sendJobStart(string $class, int $id): void {
		$this->logger->info('CONTEXT_CHAT_DIAGNOSTICS: ' . $class . ' ' . $id . ' started');
		$key = $class . '-' . $id;
		try {
			$diagnostics = $this->getBackgroundJobDiagnostics();
			if (!isset($diagnostics[$key])) {
				$diagnostics[$key] = [];
			}
			$diagnostics[$key] = array_merge(['last_started' => time()], $diagnostics[$key]);
			if (isset($diagnostics[$key]['started_count'])) {
				$diagnostics[$key]['started_count']++;
			} else {
				$diagnostics[$key]['started_count'] = 1;
			}
			$this->setBackgroundJobDiagnostics($diagnostics);
		} catch (\OCP\Exceptions\AppConfigTypeConflictException|\JsonException  $e) {
			$this->logger->warning('Error during context chat diagnostic jobStart', ['exception' => $e]);
		}
	}

	/**
	 * @param string $class
	 * @param int $id
	 * @return void
	 */
	public function sendJobEnd(string $class, int $id): void {
		$this->logger->info('CONTEXT_CHAT_DIAGNOSTICS: ' . $class . ' ' . $id . ' ended');
		$key = $class . '-' . $id;
		try {
			$diagnostics = $this->getBackgroundJobDiagnostics();
			if (!isset($diagnostics[$key])) {
				$diagnostics[$key] = [];
			}
			$diagnostics[$key] = array_merge(['last_ended' => time()], $diagnostics[$key]);
			$this->setBackgroundJobDiagnostics($diagnostics);
		} catch (\OCP\Exceptions\AppConfigTypeConflictException|\JsonException  $e) {
			$this->logger->warning('Error during context chat diagnostic jobStart', ['exception' => $e]);
		}
	}

	/**
	 * @param string $class
	 * @param int $id
	 * @return void
	 */
	public function sendHeartbeat(string $class, int $id): void {
		$this->logger->info('CONTEXT_CHAT_DIAGNOSTICS: ' . $class . ' ' . $id . ' running');
		$key = $class . '-' . $id;
		try {
			$diagnostics = $this->getBackgroundJobDiagnostics();
			if (!isset($diagnostics[$key])) {
				$diagnostics[$key] = [];
			}
			$diagnostics[$key] = array_merge(['last_seen' => time()], $diagnostics[$key]);
			$this->setBackgroundJobDiagnostics($diagnostics);
		} catch (\OCP\Exceptions\AppConfigTypeConflictException|\JsonException  $e) {
			$this->logger->warning('Error during context chat diagnostic heartbeat', ['exception' => $e]);
		}
	}

	/**
	 * @param int $count
	 * @return void
	 */
	public function sendIndexedFiles(int $count): void {
		$this->logger->info('CONTEXT_CHAT_DIAGNOSTICS: Indexed ' . $count . ' files');
		// We use numericToNumber to fall back to float in case int is too small
		$this->appConfig->setAppValueString('indexed_files_count', (string)Util::numericToNumber($count + Util::numericToNumber($this->appConfig->getAppValueString('indexed_files_count', '0', false))), false);
	}
}
