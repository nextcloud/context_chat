<?php

/*
 * Copyright (c) 2022 The Recognize contributors.
 * Copyright (c) 2023 Marcel Klehr <mklehr@gmx.net>
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\ContextChat\Listener;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Public\UpdateAccessOp;
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
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\Events\NodeRemovedFromCache;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Share\Events\ShareCreatedEvent;
use OCP\Share\Events\ShareDeletedEvent;
use OCP\Share\IManager;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class FileListener implements IEventListener {

	public function __construct(
		private LoggerInterface $logger,
		private QueueService $queue,
		private StorageService $storageService,
		private IManager $shareManager,
		private IRootFolder $rootFolder,
		private ActionService $actionService,
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

		if ($event instanceof ShareCreatedEvent) {
			$share = $event->getShare();
			$node = $share->getNode();

			switch ($share->getShareType()) {
				case \OCP\Share\IShare::TYPE_USER:
					$userIds = [$share->getSharedWith()];
					break;
				case \OCP\Share\IShare::TYPE_GROUP:
					// todo: probably a group listener so when a user enters/leaves a group, we can update the access for all files shared with that group
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
				$mount = $node->getMountPoint();
				if ($mount->getNumericStorageId() === null) {
					return;
				}
				$files = $this->storageService->getFilesInMount($mount->getNumericStorageId(), $node->getId(), 0, 0);
				foreach ($files as $fileId) {
					$file = current($this->rootFolder->getById($fileId));
					if (!$file instanceof File) {
						continue;
					}
					$this->actionService->updateAccess(
						UpdateAccessOp::ALLOW,
						$userIds,
						ProviderConfigService::getSourceId($file->getId()),
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

			$accessList = $this->shareManager->getAccessList($node, true, true);
			/**
			 * @var string[] $userIds
			 */
			$userIds = array_keys($accessList['users']);

			if ($node instanceof Folder) {
				$mount = $node->getMountPoint();
				if ($mount->getNumericStorageId() === null) {
					return;
				}
				$files = $this->storageService->getFilesInMount($mount->getNumericStorageId(), $node->getId(), 0, 0);
				$fileRefs = [];

				foreach ($files as $fileId) {
					$node = current($this->rootFolder->getById($fileId));
					if (!$node instanceof File) {
						continue;
					}
					$fileRefs[] = ProviderConfigService::getSourceId($node->getId());
				}

				foreach ($fileRefs as $fileRef) {
					$this->actionService->updateAccess(
						UpdateAccessOp::DENY,
						$userIds,
						$fileRef,
					);
				}
			} else {
				if (!$this->allowedMimeType($node)) {
					return;
				}

				$fileRef = ProviderConfigService::getSourceId($node->getId());
				$this->actionService->updateAccess(
					UpdateAccessOp::DENY,
					$userIds,
					$fileRef,
				);
			}
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

	private function allowedMimeType(File $file): bool {
		$mimeType = $file->getMimeType();
		return in_array($mimeType, Application::MIMETYPES, true);
	}
}
