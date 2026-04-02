<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\StatisticsService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Statistics extends Command {

	public function __construct(
		private StatisticsService $statisticsService,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:stats')
			->setDescription('Check ContextChat statistics')
			->addOption(
				'json',
				null,
				InputOption::VALUE_NONE,
				'Output the statistics in json format',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$stats = $this->statisticsService->getStatistics();
		if ($input->getOption('json')) {
			$prettyJson = json_encode($stats, JSON_PRETTY_PRINT);
			if ($prettyJson === false) {
				$output->writeln('{"error": "Failed to encode statistics to JSON."}');
				return 1;
			}
			$output->writeln($prettyJson);
			return 0;
		}

		$output->writeln('ContextChat statistics:');
		$output->writeln('Installed time: ' . (new \DateTime('@' . $stats['installed_at']))->format('Y-m-d H:i') . ' UTC');
		if (!$stats['initial_indexing_complete']) {
			$output->writeln('The indexing is not complete yet.');
		} else {
			$indexTime = $stats['intial_indexing_completed_at'] - $stats['installed_at'];
			$output->writeln('Index complete time: ' . (new \DateTime('@' . ($stats['intial_indexing_completed_at'] ?? '')))->format('Y-m-d H:i') . ' UTC');
			$output->writeln('Total time taken for complete index: ' . strval(floor($indexTime / (60 * 60 * 24))) . ' days ' . gmdate('H:i', $indexTime) . ' (hh:mm)');
		}

		$output->writeln('Context Chat Backend reachable: ' . ($stats['backend_available'] ? 'Yes' : 'No'));
		$output->writeln('Total eligible files: ' . $stats['eligible_files_count']);
		$output->writeln('Files in indexing queue: ' . ($stats['queued_documents_counts'][ProviderConfigService::getDefaultProviderKey()] ?? 0));
		$output->writeln('Locked files in indexing queue: ' . ($stats['queued_documents_locked_counts'][ProviderConfigService::getDefaultProviderKey()] ?? 0));
		$output->writeln('New files in indexing queue (without updates): ' . $stats['queued_new_files_count']);
		$output->writeln('Queued documents:' . var_export($stats['queued_documents_counts'] ?? [], true));
		$output->writeln('Locked queue documents:' . var_export($stats['queued_documents_locked_counts'] ?? [], true));
		$output->writeln('Files successfully sent to backend: ' . strval($stats['recorded_indexed_files_count']));
		$output->writeln('Indexed documents: ' . var_export($stats['vectordb_document_counts'], true));
		$output->writeln('Actions in queue: ' . $stats['queued_actions_count']);
		$output->writeln('Locked actions in queue: ' . $stats['queued_actions_locked_count']);
		$output->writeln('File system events in queue: ' . $stats['queued_fs_events_count']);
		return 0;
	}
}
