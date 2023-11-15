<?php
/*
 * Copyright (c) 2022 The Recognize contributors.
 * Copyright (c) 2023 Marcel Klehr <mklehr@gmx.net>
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Cwyd\Listener;

use OCA\Cwyd\Db\QueueFile;
use OCA\Cwyd\Service\LangRopeService;
use OCA\Cwyd\Service\QueueService;
use OCA\Cwyd\Service\StorageService;
use OCA\Cwyd\Type\Source;
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
use OCP\Files\NotPermittedException;
use OCP\Lock\LockedException;
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
		private LangRopeService $langRopeService) {
	}

	public function handle(Event $event): void {
		if ($event instanceof NodeWrittenEvent) {
			$node = $event->getNode();
			if ($node instanceof File) {
				return;
			}
			$this->postInsert($node, false, true);
		}

		if ($event instanceof ShareCreatedEvent) {
			$share = $event->getShare();
			$ownerId = $share->getShareOwner();
			$node = $share->getNode();

			$accessList = $this->shareManager->getAccessList($node, true, true);
			/**
			 * @var string[] $userIds
			 */
			$userIds = array_keys($accessList['users']);

			if ($node->getType() === FileInfo::TYPE_FOLDER) {
				$mount = $node->getMountPoint();
				if ($mount->getNumericStorageId() === null) {
					return;
				}
				$files = $this->storageService->getFilesInMount($mount->getNumericStorageId(), $node->getId(), 0, 0);
				foreach ($files as $fileId) {
					foreach ($userIds as $userId) {
						if ($userId === $ownerId) {
							continue;
						}
						$file = current($this->rootFolder->getById($fileId));
						if (!$file instanceof File) {
							continue;
						}
						$this->postInsert($file, false, true);
					}
				}
			} else {
				foreach ($userIds as $userId) {
					if ($userId === $ownerId) {
						continue;
					}
					if (!$node instanceof File) {
						continue;
					}
					$this->postInsert($node, false, true);
				}
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

			if ($node->getType() === FileInfo::TYPE_FOLDER) {
				$mount = $node->getMountPoint();
				if ($mount->getNumericStorageId() === null) {
					return;
				}
				$files = $this->storageService->getFilesInMount($mount->getNumericStorageId(), $node->getId(), 0, 0);
				foreach ($files as $fileId) {
					$this->postInsert(current($this->rootFolder->getById($fileId)), false, true);
				}
			} else {
				// TODO: remove index entry of $node for $userIds
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
				$this->logger->debug($e->getMessage(), ['exception' => $e]);
			}
			return;
		}
		try {
			$fileHandle = $node->fopen('r');
		} catch (LockedException|NotPermittedException $e) {
			$this->logger->error('Could not open file ' . $node->getPath() . ' for reading', ['exception' => $e]);
			return;
		}
		foreach ($this->storageService->getUsersForFileId($node->getId()) as $userId) {
			try {
				$source = new Source($userId, 'file: ' . $node->getId(), $fileHandle, $node->getMtime(), $node->getMimeType());
			} catch (InvalidPathException|NotFoundException $e) {
				$this->logger->error('Could not find file ' . $node->getPath(), ['exception' => $e]);
				break;
			}
			$this->langRopeService->deleteSources($userId, [$source]);
		}
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
				$this->logger->debug($e->getMessage(), ['exception' => $e]);
			}
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
			return;
		}
	}
}
