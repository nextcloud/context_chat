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
use Psr\Log\LogLevel;
use Stringable;

/**
 * Logger that logs in the context chat log file instead of the normal log file
 */
class Logger {

	private LoggerInterface $parentLogger;

	public function __construct(
		ILogFactory $logFactory,
		private IConfig $config,
	) {
		$logFilepath = $this->getLogFilepath();
		$this->parentLogger = $logFactory->getCustomPsrLogger($logFilepath, 'file', 'Nextcloud Context Chat');
	}

	public function getLogFilepath(): string {
		$default = $this->config->getSystemValue('datadirectory', \OC::$SERVERROOT . '/data') . '/context_chat.log';
		// Legacy way was appconfig, now it's paralleled with the normal log config
		return $this->config->getAppValue(Application::APP_ID, 'logfile', $default);
	}

	public function emergency(Stringable|string $message, array $context = []): void {
		$this->parentLogger->emergency($message, $context);
	}

	public function alert(Stringable|string $message, array $context = []): void {
		$this->parentLogger->alert($message, $context);
	}

	public function critical(Stringable|string $message, array $context = []): void {
		$this->parentLogger->critical($message, $context);
	}

	public function error(Stringable|string $message, array $context = []): void {
		$this->parentLogger->critical($message, $context);
	}

	public function warning(Stringable|string $message, array $context = []): void {
		$this->parentLogger->critical($message, $context);
	}

	public function notice(Stringable|string $message, array $context = []): void {
		$this->parentLogger->critical($message, $context);
	}

	public function info(Stringable|string $message, array $context = []): void {
		$this->parentLogger->critical($message, $context);
	}

	public function debug(Stringable|string $message, array $context = []): void {
		// critical level is used here and at other places to not miss any message
		// from context chat when the server's log level is set to a higher level
		$this->parentLogger->critical($message, $context);
	}

	public function log(LogLevel $level, Stringable|string $message, array $context = []): void {
		$this->parentLogger->log($level, $message, $context);
	}
}
