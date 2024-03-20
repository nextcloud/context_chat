<?php

/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2023
 */

namespace OCA\ContextChat\Command;

use OCA\ContextChat\TextProcessing\ContextChatTaskType;
use OCA\ContextChat\Type\ScopeType;
use OCP\TextProcessing\FreePromptTaskType;
use OCP\TextProcessing\IManager;
use OCP\TextProcessing\Task;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Prompt extends Command {

	public function __construct(
		private IManager $textProcessingManager,
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
				'no-context',
				null,
				InputOption::VALUE_NONE,
				'Do not use context'
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

	protected function execute(InputInterface $input, OutputInterface $output) {
		$userId = $input->getArgument('uid');
		$prompt = $input->getArgument('prompt');
		$noContext = $input->getOption('no-context');
		$contextSources = $input->getOption('context-sources');
		$contextProviders = $input->getOption('context-providers');

		if ($noContext && (!empty($contextSources) || !empty($contextProviders))) {
			throw new \InvalidArgumentException('Cannot use --no-context with --context-sources or --context-provider');
		}

		if (!empty($contextSources) && !empty($contextProviders)) {
			throw new \InvalidArgumentException('Cannot use --context-sources with --context-provider');
		}

		try {
			if ($noContext) {
				$task = new Task(FreePromptTaskType::class, $prompt, 'context_chat', $userId);
			} elseif (!empty($contextSources)) {
				$contextSources = preg_replace('/\s*,+\s*/', ',', $contextSources);
				$contextSourcesArray = array_filter(explode(',', $contextSources), fn ($source) => !empty($source));
				$task = new Task(ContextChatTaskType::class, json_encode([
					'scopeType' => ScopeType::SOURCE,
					'scopeList' => $contextSourcesArray,
					'prompt' => $prompt,
				], JSON_THROW_ON_ERROR), 'context_chat', $userId);
			} elseif (!empty($contextProviders)) {
				$contextProviders = preg_replace('/\s*,+\s*/', ',', $contextProviders);
				$contextProvidersArray = array_filter(explode(',', $contextProviders), fn ($source) => !empty($source));
				$task = new Task(ContextChatTaskType::class, json_encode([
					'scopeType' => ScopeType::PROVIDER,
					'scopeList' => $contextProvidersArray,
					'prompt' => $prompt,
				], JSON_THROW_ON_ERROR), 'context_chat', $userId);
			} else {
				$task = new Task(ContextChatTaskType::class, json_encode([ 'prompt' => $prompt ], JSON_THROW_ON_ERROR), 'context_chat', $userId);
			}
		} catch (\JsonException $e) {
			throw new \InvalidArgumentException('Invalid input, cannot encode JSON', intval($e->getCode()), $e);
		}

		$this->textProcessingManager->runTask($task);
		$output->writeln($task->getOutput());

		return 0;
	}
}
