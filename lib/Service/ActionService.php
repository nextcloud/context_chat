<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\BackgroundJobs\ActionJob;
use OCA\ContextChat\Db\QueueAction;
use OCA\ContextChat\Db\QueueActionMapper;
use OCA\ContextChat\Type\ActionType;
use OCA\ContextChat\Type\UpdateAccessOp;
use OCP\BackgroundJob\IJobList;
use Psr\Log\LoggerInterface;

class ActionService {
	private const BATCH_SIZE = 500;

	public function __construct(
		private IJobList $jobList,
		private QueueActionMapper $actionMapper,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param ActionType::* $type
	 * @param string $payload
	 * @return void
	 */
	private function scheduleAction(string $type, string $payload): void {
		$item = new QueueAction();
		$item->setType($type);
		$item->setPayload($payload);

		// do not catch DB exceptions
		$this->actionMapper->insertIntoQueue($item);

		if (!$this->jobList->has(ActionJob::class, null)) {
			$this->jobList->add(ActionJob::class, null);
		}
	}

	/**
	 * @param string[] $sourceIds
	 * @return void
	 */
	public function deleteSources(string ...$sourceIds): void {
		// batch sourceIds into self::BATCH_SIZE chunks
		$batches = array_chunk($sourceIds, self::BATCH_SIZE);

		foreach ($batches as $batch) {
			$payload = json_encode(['sourceIds' => $batch]);
			if ($payload === false) {
				$this->logger->warning('Failed to json_encode sourceIds for deletion', ['sourceIds' => $batch]);
				continue;
			}
			$this->scheduleAction(ActionType::DELETE_SOURCE_IDS, $payload);
		}
	}

	/**
	 * @param string $providerKey
	 * @return void
	 */
	public function deleteProvider(string $providerKey): void {
		$payload = json_encode(['providerId' => $providerKey]);
		if ($payload === false) {
			$this->logger->warning('Failed to json_encode providerId for deletion', ['providerId' => $providerKey]);
			return;
		}
		$this->scheduleAction(ActionType::DELETE_PROVIDER_ID, $payload);
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	public function deleteUser(string $userId): void {
		$payload = json_encode(['userId' => $userId]);
		if ($payload === false) {
			$this->logger->warning('Failed to json_encode userId for deletion', ['userId' => $userId]);
			return;
		}
		$this->scheduleAction(ActionType::DELETE_USER_ID, $payload);
	}

	/**
	 * @param UpdateAccessOp::* $op
	 * @param string[] $userIds
	 * @param string $sourceId
	 * @return void
	 */
	public function updateAccess(string $op, array $userIds, string $sourceId): void {
		if (count($userIds) === 0) {
			$this->logger->warning('userIds array is empty, ignoring this update', ['sourceId' => $sourceId]);
			return;
		}
		$payload = json_encode(['op' => $op, 'userIds' => $userIds, 'sourceId' => $sourceId]);
		if ($payload === false) {
			$this->logger->warning('Failed to json_encode access update for source', ['op' => $op, 'sourceId' => $sourceId]);
			return;
		}
		$this->scheduleAction(ActionType::UPDATE_ACCESS_SOURCE_ID, $payload);
	}

	/**
	 * @param UpdateAccessOp::* $op
	 * @param string[] $userIds
	 * @param string $providerId
	 * @return void
	 */
	public function updateAccessProvider(string $op, array $userIds, string $providerId): void {
		if (count($userIds) === 0) {
			$this->logger->warning('userIds array is empty, ignoring this update', ['sourceId' => $providerId]);
			return;
		}
		$payload = json_encode(['op' => $op, 'userIds' => $userIds, 'providerId' => $providerId]);
		if ($payload === false) {
			$this->logger->warning('Failed to json_encode access update for provider', ['op' => $op, 'providerId' => $providerId]);
			return;
		}
		$this->scheduleAction(ActionType::UPDATE_ACCESS_PROVIDER_ID, $payload);
	}

	/**
	 * @param string[] $userIds
	 * @param string $sourceId
	 * @return void
	 */
	public function updateAccessDeclSource(array $userIds, string $sourceId): void {
		if (count($userIds) === 0) {
			$this->logger->warning('userIds array is empty, ignoring this update', ['sourceId' => $sourceId]);
			return;
		}
		$payload = json_encode(['userIds' => $userIds, 'sourceId' => $sourceId]);
		if ($payload === false) {
			$this->logger->warning('Failed to json_encode access update declarative for source', ['sourceId' => $sourceId]);
			return;
		}
		$this->scheduleAction(ActionType::UPDATE_ACCESS_DECL_SOURCE_ID, $payload);
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function count(): int {
		return $this->actionMapper->count();
	}
}
