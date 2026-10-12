<?php

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Command;

use OCP\TaskProcessing\Exception\Exception as TaskProcessingException;
use OCP\TaskProcessing\Exception\NotFoundException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\Task;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SignalRegistry\SignalRegistry;

/**
 * Schedules a task, waits for it to reach a terminal state and prints its result.
 *
 * The scheduled task is cancelled when the command is interrupted, so a task is
 * never left running after the console process is gone. Classes using this trait
 * must implement {@see \Symfony\Component\Console\Command\SignalableCommandInterface}.
 */
trait AwaitTaskTrait {
	private ?IManager $awaitManager = null;
	private ?OutputInterface $awaitOutput = null;
	private ?int $awaitTaskId = null;

	/**
	 * @return list<int>
	 */
	public function getSubscribedSignals(): array {
		// mirror Symfony's own precondition: subscribing without a signal registry throws
		if (!\defined('SIGINT') || !SignalRegistry::isSupported()) {
			return [];
		}

		return [SIGINT, SIGTERM];
	}

	public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false {
		$taskId = $this->awaitTaskId;
		$this->awaitTaskId = null;

		if ($taskId !== null && $this->awaitManager !== null) {
			try {
				$this->awaitManager->cancelTask($taskId);
				$this->awaitOutput?->writeln('<comment>Cancelled task ' . $taskId . '</comment>');
			} catch (TaskProcessingException $e) {
				$this->awaitOutput?->writeln('<error>Failed to cancel task ' . $taskId . ': ' . $e->getMessage() . '</error>');
			}
		}

		// the task produced no result, so never report success to the caller
		return 128 + $signal;
	}

	/**
	 * Schedules the task and blocks until it reaches a terminal state.
	 *
	 * @return int the exit code of the command
	 */
	private function scheduleAndAwaitTask(IManager $manager, Task $task, OutputInterface $output): int {
		$this->awaitManager = $manager;
		$this->awaitOutput = $output;

		$manager->scheduleTask($task);
		$taskId = $task->getId();
		if ($taskId === null) {
			$output->writeln('<error>Task schedule failed, taskId is null</error>');
			return 1;
		}
		$this->awaitTaskId = $taskId;

		try {
			// STATUS_UNKNOWN is terminal as well: the task will never make progress from there
			$terminalStatuses = [
				Task::STATUS_SUCCESSFUL,
				Task::STATUS_FAILED,
				Task::STATUS_CANCELLED,
				Task::STATUS_UNKNOWN,
			];

			while (!in_array(($task = $manager->getTask($taskId))->getStatus(), $terminalStatuses, true)) {
				sleep(2);
			}
		} catch (NotFoundException $e) {
			$output->writeln('<error>Task ' . $taskId . ' has disappeared: ' . $e->getMessage() . '</error>');
			return 1;
		} finally {
			$this->awaitTaskId = null;
		}

		switch ($task->getStatus()) {
			case Task::STATUS_SUCCESSFUL:
				$output->writeln(var_export($task->getOutput(), true));
				return 0;
			case Task::STATUS_CANCELLED:
				$output->writeln('<comment>Task ' . $taskId . ' was cancelled</comment>');
				return 1;
			case Task::STATUS_UNKNOWN:
				$output->writeln('<error>Task ' . $taskId . ' is in an unknown state</error>');
				return 1;
			default:
				$output->writeln('<error>' . ($task->getErrorMessage() ?? '(empty error message)') . '</error>');
				return 1;
		}
	}
}
