<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2022-2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat;

use OCA\ContextChat\AppInfo\Application;
use OCP\IConfig;
use OCP\Log\ILogFactory;
use Psr\Log\LoggerInterface;

/**
 * Logger that logs in the context chat log file instead of the normal log file
 */
class Logger {

	private LoggerInterface $parentLogger;

	public function __construct(
		ILogFactory $logFactory,
		IConfig $config,
	) {
		$default = $config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data') . '/context_chat.log';
		// Legacy way was appconfig, now it's paralleled with the normal log config
		$logFile = $config->getAppValue(Application::APP_ID, 'logfile', $default);
		$this->parentLogger = $logFactory->getCustomPsrLogger($logFile, 'file', 'Nextcloud Context Chat');
	}

	public function emergency($message, array $context = []): void {
		$this->parentLogger->emergency($message, $context);
	}

	public function alert($message, array $context = []): void {
		$this->parentLogger->alert($message, $context);
	}

	public function critical($message, array $context = []): void {
		$this->parentLogger->critical($message, $context);
	}

	public function error($message, array $context = []): void {
		$this->parentLogger->critical($message, $context);
	}

	public function warning($message, array $context = []): void {
		$this->parentLogger->critical($message, $context);
	}

	public function notice($message, array $context = []): void {
		$this->parentLogger->critical($message, $context);
	}

	public function info($message, array $context = []): void {
		$this->parentLogger->critical($message, $context);
	}

	public function debug($message, array $context = []): void {
		$this->parentLogger->critical($message, $context);
	}

	public function log($level, $message, array $context = []): void {
		$this->parentLogger->critical($message, $context);
	}
}
