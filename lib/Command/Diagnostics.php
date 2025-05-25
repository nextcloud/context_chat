<?php

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use LimitIterator;
use LogicException;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\DiagnosticService;
use RuntimeException;
use SplFileObject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Diagnostics extends Command {

	public function __construct(
		private DiagnosticService $diagnosticService,
		private Logger $logger,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:diagnostics')
			->setDescription('Check diagnostic logs of ContextChat')
			->setHelp('This command allows you to check the diagnostic logs of ContextChat. It will display the last N entries from the diagnostic log. By default, it shows the last 10 entries.')
			->addArgument('count', InputArgument::OPTIONAL, 'The number of log entries to display', 10);
	}

	/**
	 * https://stackoverflow.com/a/34981383
	 */
	private function readLastNLines(string $filePath, int $n): array {
		try {
			$file = new SplFileObject($filePath, 'r');
			// last line of the file
			$file->seek(PHP_INT_MAX);
			$last_line = $file->key();
			$lines = new LimitIterator($file, max(0, $last_line - $n), $last_line);
			return iterator_to_array($lines);
		} catch (RuntimeException $e) {
			throw new RuntimeException('Could not read the file: ' . $filePath, 0, $e);
		} catch (LogicException $e) {
			throw new RuntimeException('The path provided is invalid or a directory: ' . $filePath, 0, $e);
		} catch (\Exception $e) {
			throw new RuntimeException('An error occurred while reading the file: ' . $filePath, 0, $e);
		}
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$logFilepath = $this->logger->getLogFilePath();
		if (!file_exists($logFilepath)) {
			$output->writeln('<error>Diagnostic log file does not exist: ' . $logFilepath . '</error>');
			return 1;
		}

		$lastN = (int)$input->getArgument('count');
		if ($lastN <= 0) {
			$output->writeln('<error>Invalid count specified. Please provide a positive integer.</error>');
			return 1;
		}
		$logEntries = $this->readLastNLines($logFilepath, $lastN);
		if (empty($logEntries)) {
			$output->writeln('<info>No diagnostic log entries found.</info>');
			return 0;
		}

		$output->writeln('<info>Last 10 diagnostic log entries:</info>');
		foreach ($logEntries as $entry) {
			$output->write($entry);
		}
		return 0;
	}
}
