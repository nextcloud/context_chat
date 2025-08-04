<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2022 The Recognize contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Listener;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Public\UpdateAccessOp;
use OCA\ContextChat\Service\ActionScheduler;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\StorageService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IGroupManager;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IManager;

/**
 * @template-implements IEventListener<Event>
 */
class ShareListener implements IEventListener {

	public function __construct(
		private Logger $logger,
		private StorageService $storageService,
		private IManager $shareManager,
		private IRootFolder $rootFolder,
		private ActionScheduler $actionService,
		private IGroupManager $groupManager,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof ShareCreatedEvent) {
			$share = $event->getShare();
			$node = $share->getNode();

			switch ($share->getShareType()) {
				case \OCP\Share\IShare::TYPE_USER:
					$userIds = [$share->getSharedWith()];
					break;
				case \OCP\Share\IShare::TYPE_GROUP:
					$accessList = $this->shareManager->getAccessList($node, true, true);
					/**
					 * @var string[] $userIds
					 */
					$userIds = array_keys($accessList['users']);
					break;
				default:
					return;
			}

			if ($node->getType() === FileInfo::TYPE_FOLDER) {
				$fileIds = $this->storageService->getAllFilesInFolder($node);
				foreach ($fileIds as $fileId) {
					$this->actionService->updateAccess(
						UpdateAccessOp::ALLOW,
						$userIds,
						ProviderConfigService::getSourceId($fileId),
					);
				}
			} else {
				$this->actionService->updateAccess(
					UpdateAccessOp::ALLOW,
					$userIds,
					ProviderConfigService::getSourceId($node->getId()),
				);
			}
		}

		if ($event instanceof ShareDeletedEvent) {
			$share = $event->getShare();
			$node = $share->getNode();

			// fileUserIds list is not fully accurate and doesn't update until the user(s)
			//  in question logs in again, so we need to get the share access list
			//  and the user(s) from whom the file was unshared with to update the access list,
			//  keeping the access for the user(s) who still have access to the file through
			//  file mounts.

			switch ($share->getShareType()) {
				case \OCP\Share\IShare::TYPE_USER:
					$unsharedWith = [$share->getSharedWith()];
					break;
				case \OCP\Share\IShare::TYPE_GROUP:
					$unsharedWithGroup = $this->groupManager->get($share->getSharedWith());
					if ($unsharedWithGroup === null) {
						$this->logger->warning('Could not find group with id ' . $share->getSharedWith());
						return;
					}
					$unsharedWith = array_keys($unsharedWithGroup->getUsers());
					break;
				default:
					return;
			}

			$shareAccessList = $this->shareManager->getAccessList($node, true, true);
			/**
			 * @var string[] $shareUserIds
			 */
			$shareUserIds = array_keys($shareAccessList['users']);
			$fileUserIds = $this->storageService->getUsersForFileId($node->getId());

			// the user(s) who have really lost access to the file and don't have access to it
			//  through any other shares
			$reallyUnsharedWith = array_diff($unsharedWith, $shareUserIds);

			// the user(s) who have access to the file through file mounts, excluding the user(s)
			//	who have really lost access to the file and are present in $fileUserIds list
			$realFileUserIds = array_diff($fileUserIds, $reallyUnsharedWith);
			// merge the share and file lists to get the final list of user(s) who have access to the file
			$userIds = array_values(array_unique(array_merge($realFileUserIds, $shareUserIds)));

			if ($node instanceof Folder) {
				$fileIds = $this->storageService->getAllFilesInFolder($node);
				foreach ($fileIds as $fileId) {
					$this->actionService->updateAccessDeclSource(
						$userIds,
						ProviderConfigService::getSourceId($fileId),
					);
				}
			} else {
				if (!$this->allowedMimeType($node)) {
					return;
				}

				$fileRef = ProviderConfigService::getSourceId($node->getId());
				$this->actionService->updateAccessDeclSource(
					$userIds,
					$fileRef,
				);
			}
		}
	}

	private function allowedMimeType(Node $file): bool {
		$mimeType = $file->getMimeType();
		return in_array($mimeType, Application::MIMETYPES, true);
	}
}
