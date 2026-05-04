<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\Service\MultimodalService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class QueueMultimodalFiles extends Command {

	public function __construct(
		private MultimodalService $multimodalService,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:queue-multimodal-files')
			->setDescription(
				'Queue existing multimodal files (Images and Audio) for indexation.'
				. ' Each type of files is queued only if the required TaskProcessing task provider is available.'
				. ' OCR for Images and Speech-to-text for Audio.'
				. ' See https://docs.nextcloud.com/server/latest/admin_manual/ai/overview.html for more information.'
			)
			->addOption(
				'force',
				null,
				InputOption::VALUE_NONE,
				'Queue multimodal files regardless of whether it has been done previously already.',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$taskTypes = $this->multimodalService->checkTaskTypes();

		if (!$taskTypes['ocrAvailable']) {
			$output->writeln('<warning>OCR task type is not configured. Image files will not be indexed.</warning>');
		}
		if (!$taskTypes['sttAvailable']) {
			$output->writeln('<warning>Speech-to-text task type is not configured. Audio files will not be indexed.</warning>');
		}

		if (!$taskTypes['ocrAvailable'] && !$taskTypes['sttAvailable']) {
			$output->writeln('<error>No multimodal task types are available. No files will be queued for indexing.</error>');
			return 1;
		}

		try {
			$this->multimodalService->enableMultimodal((bool)$input->getOption('force'));
		} catch (\Exception $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');
			return 1;
		}

		$errors = $this->multimodalService->queueExistingMultimodalFiles();

		foreach ($errors as $error) {
			$output->writeln('<warning>' . $error . '</warning>');
		}

		if (empty($errors)) {
			$output->writeln(
				'<info>Multimodal files have been scheduled to be queued for indexation. '
				. 'They will be queued in the subsequent cron runs automatically.</info>'
			);
		}
		return empty($errors) ? 0 : 1;
	}
}
