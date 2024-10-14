<?php
/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Anupam Kumar <kyteinsky@gmail.com>
 * @copyright Anupam Kumar 2024
 */

declare(strict_types=1);

namespace OCA\ContextChat\Service;

use OCA\ContextChat\BackgroundJobs\DeleteJob;
use OCA\ContextChat\Db\QueueDelete;
use OCA\ContextChat\Db\QueueDeleteMapper;
use OCA\ContextChat\Type\DeleteContext;
use OCP\BackgroundJob\IJobList;

class DeleteService {
	public function __construct(
		private IJobList $jobList,
		private QueueDeleteMapper $deleteMapper,
	) {
	}

	/**
	 * @param string $type
	 * @param string $payload
	 * @param string $userId
	 * @return void
	 */
	public function scheduleDelete(string $type, string $payload, string $userId = ''): void {
		$item = new QueueDelete();
		$item->setType($type);
		$item->setUserId($userId);
		$item->setPayload($payload);

		// do not catch DB exceptions
		$this->deleteMapper->insertIntoQueue($item);

		if (!$this->jobList->has(DeleteJob::class, null)) {
			$this->jobList->add(DeleteJob::class, null);
		}
	}

	/**
	 * @param array<QueueDelete> $entities
	 * @return array<string, array<string>|array<string, array<string>>>
	 */
	public function bucketIntoTypes(array $entities): array {
		$bucket = [
			DeleteContext::SOURCE_ONE_USER => [],
			DeleteContext::PROVIDER_ONE_USER => [],
			DeleteContext::PROVIDER_ALL_USERS => [],
		];

		foreach ($entities as $entity) {
			$type = $entity->getType();
			$userId = $entity->getUserId();
			$payload = $entity->getPayload();

			if ($type === DeleteContext::PROVIDER_ALL_USERS) {
				$bucket[DeleteContext::PROVIDER_ALL_USERS][] = $payload;
				continue;
			}

			if (!isset($bucket[$type][$userId])) {
				$bucket[$type][$userId] = [];
			}
			$bucket[$type][$userId][] = $payload;
		}

		return $bucket;
	}

	/**
	 * @param string $providerKey
	 * @return void
	 */
	public function deleteSourcesByProviderForAllUsers(string $providerKey): void {
		$this->scheduleDelete(DeleteContext::PROVIDER_ALL_USERS, $providerKey);
	}

	/**
	 * @param string $userId
	 * @param string $providerKey
	 * @return void
	 */
	public function deleteSourcesByProvider(string $userId, string $providerKey): void {
		$this->scheduleDelete(DeleteContext::PROVIDER_ONE_USER, $providerKey, $userId);
	}

	/**
	 * @param string $userId
	 * @param string[] $sourceNames
	 * @return void
	 */
	public function deleteSources(string $userId, array $sourceNames): void {
		foreach ($sourceNames as $source) {
			$this->scheduleDelete(DeleteContext::SOURCE_ONE_USER, $source, $userId);
		}
	}
}
