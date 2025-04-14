<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2022 The Recognize contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Listener;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\ActionService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\QueueService;
use OCA\ContextChat\Service\StorageService;
use OCP\DB\Exception;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Events\Node\BeforeNodeDeletedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Events\NodeRemovedFromCache;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Share\IManager;

/**
 * @template-implements IEventListener<Event>
 */
class FileListener implements IEventListener {

	public function __construct(
		private Logger $logger,
		private QueueService $queue,
		private IRootFolder $rootFolder,
		private ActionService $actionService,
		private StorageService $storageService,
		private IManager $shareManager,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof NodeWrittenEvent) {
			$node = $event->getNode();
			if (!$node instanceof File) {
				return;
			}
			$this->postInsert($node, false, true);
		}

		if ($event instanceof BeforeNodeDeletedEvent) {
			$this->postDelete($event->getNode(), false);
			return;
		}

		if ($event instanceof NodeCreatedEvent) {
			$this->postInsert($event->getNode(), false);
			return;
		}

		if ($event instanceof CacheEntryInsertedEvent) {
			$node = current($this->rootFolder->getById($event->getFileId()));
			if ($node === false) {
				return;
			}
			if ($node instanceof Folder) {
				return;
			}
			$this->postInsert($node);
			return;
		}

		if ($event instanceof NodeRenamedEvent) {
			$targetNode = $event->getTarget();

			if ($targetNode instanceof Folder) {
				$files = $this->storageService->getAllFilesInFolder($targetNode);
			} else {
				$files = [$targetNode];
			}

			foreach ($files as $file) {
				if (!$file instanceof File) {
					continue;
				}
				$shareAccessList = $this->shareManager->getAccessList($file, true, true);
				/**
				 * @var string[] $shareUserIds
				 */
				$shareUserIds = array_keys($shareAccessList['users']);
				$fileUserIds = $this->storageService->getUsersForFileId($file->getId());

				$userIds = array_values(array_unique(array_merge($shareUserIds, $fileUserIds)));
				$fileRef = ProviderConfigService::getSourceId($file->getId());
				$this->actionService->updateAccessDeclSource($userIds, $fileRef);
			}
			return;
		}

		if ($event instanceof NodeRemovedFromCache) {
			$cacheEntry = $event->getStorage()->getCache()->get($event->getPath());
			if ($cacheEntry === false) {
				return;
			}
			$node = current($this->rootFolder->getById($cacheEntry->getId()));
			if ($node === false) {
				return;
			}
			$this->postDelete($node);
		}
	}

	public function postDelete(Node $node, bool $recurse = true): void {
		if (!$node instanceof File) {
			if (!$recurse) {
				return;
			}
			// For normal inserts we probably get one event per node, but, when removing an ignore file,
			// we only get the folder passed here, so we recurse.
			try {
				/** @var Folder $node */
				foreach ($node->getDirectoryListing() as $child) {
					$this->postDelete($child);
				}
			} catch (NotFoundException $e) {
				$this->logger->warning($e->getMessage(), ['exception' => $e]);
			}
			return;
		}

		if (!$this->allowedMimeType($node)) {
			return;
		}

		$fileRef = ProviderConfigService::getSourceId($node->getId());
		$this->actionService->deleteSources($fileRef);
	}

	/**
	 * @throws \OCP\Files\InvalidPathException
	 */
	public function postInsert(Node $node, bool $recurse = true, bool $update = false): void {
		if ($node->getType() === FileInfo::TYPE_FOLDER) {
			if (!$recurse) {
				return;
			}
			// For normal inserts we probably get one event per node, but, when removing an ignore file,
			// we only get the folder passed here, so we recurse.
			try {
				/** @var Folder $node */
				foreach ($node->getDirectoryListing() as $child) {
					$this->postInsert($child);
				}
			} catch (NotFoundException $e) {
				$this->logger->warning($e->getMessage(), ['exception' => $e]);
			}
			return;
		}

		if (!$this->allowedMimeType($node)) {
			return;
		}

		if (!$this->allowedPath($node)) {
			return;
		}

		$queueFile = new QueueFile();
		if ($node->getMountPoint()->getNumericStorageId() === null) {
			return;
		}
		$queueFile->setStorageId($node->getMountPoint()->getNumericStorageId());
		$queueFile->setRootId($node->getMountPoint()->getStorageRootId());

		try {
			$queueFile->setFileId($node->getId());
		} catch (InvalidPathException|NotFoundException $e) {
			$this->logger->warning($e->getMessage(), ['exception' => $e]);
			return;
		}

		$queueFile->setUpdate($update);
		try {
			$this->queue->insertIntoQueue($queueFile);
		} catch (Exception $e) {
			$this->logger->error('Failed to add file to queue', ['exception' => $e]);
		}
	}

	private function allowedMimeType(Node $file): bool {
		$mimeType = $file->getMimeType();
		return in_array($mimeType, Application::MIMETYPES, true);
	}

	private function allowedPath(Node $file): bool {
		$path = $file->getPath();
		return !preg_match('/^\/.+\/(files_versions|files_trashbin)\/.+/', $path, $matches);
	}
}
