<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\TaskProcessing\ContextChatTaskType;
use OCA\ContextChat\Type\ScopeType;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Prompt extends Command {

	public function __construct(
		private IManager $taskProcessingManager,
	) {
		parent::__construct();
	}

	protected function configure() {
		$this->setName('context_chat:prompt')
			->setDescription('Prompt Nextcloud Assistant Context Chat')
			->addArgument(
				'uid',
				InputArgument::REQUIRED,
				'The ID of the user to prompt the documents of'
			)
			->addArgument(
				'prompt',
				InputArgument::REQUIRED,
				'The prompt'
			)
			->addOption(
				'context-sources',
				null,
				InputOption::VALUE_REQUIRED,
				'Context sources to use (as a comma-separated list without brackets)',
			)
			->addOption(
				'context-providers',
				null,
				InputOption::VALUE_REQUIRED,
				'Context providers to use (as a comma-separated list without brackets)',
			);
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('uid');
		$prompt = $input->getArgument('prompt');
		$contextSources = $input->getOption('context-sources');
		$contextProviders = $input->getOption('context-providers');

		if (!empty($contextSources) && !empty($contextProviders)) {
			throw new \InvalidArgumentException('Cannot use --context-sources with --context-provider');
		}

		if (!empty($contextSources)) {
			$contextSources = preg_replace('/\s*,+\s*/', ',', $contextSources);
			$contextSourcesArray = array_filter(explode(',', $contextSources), fn ($source) => !empty($source));
			$task = new Task(ContextChatTaskType::ID, [
				'scopeType' => ScopeType::SOURCE,
				'scopeList' => $contextSourcesArray,
				'scopeListMeta' => '',
				'prompt' => $prompt,
			], 'context_chat', $userId);
		} elseif (!empty($contextProviders)) {
			$contextProviders = preg_replace('/\s*,+\s*/', ',', $contextProviders);
			$contextProvidersArray = array_filter(explode(',', $contextProviders), fn ($source) => !empty($source));
			$task = new Task(ContextChatTaskType::ID, [
				'scopeType' => ScopeType::PROVIDER,
				'scopeList' => $contextProvidersArray,
				'scopeListMeta' => '',
				'prompt' => $prompt,
			], 'context_chat', $userId);
		} else {
			$task = new Task(ContextChatTaskType::ID, [ 'prompt' => $prompt, 'scopeType' => ScopeType::NONE, 'scopeList' => [], 'scopeListMeta' => '' ], 'context_chat', $userId);
		}

		$this->taskProcessingManager->scheduleTask($task);
		while (!in_array(($task = $this->taskProcessingManager->getTask($task->getId()))->getStatus(), [Task::STATUS_FAILED, Task::STATUS_SUCCESSFUL], true)) {
			sleep(1);
		}
		if ($task->getStatus() === Task::STATUS_SUCCESSFUL) {
			$output->writeln(var_export($task->getOutput(), true));
			return 0;
		} else {
			$output->writeln($task->getErrorMessage());
			return 1;
		}
	}
}
