<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2022 The Recognize contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Listener;

use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\FsEventScheduler;
use OCA\ContextChat\Service\FsEventService;
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
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;

/**
 * @template-implements IEventListener<Event>
 */
class FileListener implements IEventListener {

	public function __construct(
		private Logger $logger,
		private IRootFolder $rootFolder,
		// Executes fs event listening code synchronously
		private FsEventService $fsEventService,
		// Executes fs event listening code asynchronously
		private FsEventScheduler $fsEventScheduler,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof NodeWrittenEvent) {
				$node = $event->getNode();
				if (!$node instanceof File) {
					return;
				}
				// Synchronous, because we don't recurse
				$this->fsEventService->onInsert($node, false, true);
			}

			if ($event instanceof BeforeNodeDeletedEvent) {
				// Synchronous, because we don't recurse
				$this->fsEventService->onDelete($event->getNode(), false);
				return;
			}

			if ($event instanceof NodeCreatedEvent) {
				// Synchronous, because we don't recurse
				$this->fsEventService->onInsert($event->getNode(), false);
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
				// Synchronous, because we don't recurse
				$this->fsEventService->onInsert($node);
				return;
			}

			// This event is also used for moves
			if ($event instanceof NodeRenamedEvent) {
				$targetNode = $event->getTarget();
				// Asynchronous, because we potentially recurse
				$this->fsEventScheduler->onInsert($targetNode);
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
				// todo: not sure if this need to be synchronous
				// Synchronous
				$this->fsEventService->onDelete($node);
			}

			if ($event instanceof \OCP\Files\Config\Event\UserMountAddedEvent) {
				$node = $event->mountPoint->getMountPointNode();
				if ($node === null) {
					return;
				}
				// Asynchronous, because we potentially recurse and this event needs to be handled fast
				$this->fsEventScheduler->onAccessUpdate($node);
			}

			if ($event instanceof \OCP\Files\Config\Event\UserMountRemovedEvent) {
				$node = $event->mountPoint->getMountPointNode();
				if ($node === null) {
					return;
				}
				// Asynchronous, because we potentially recurse and this event needs to be handled fast
				$this->fsEventScheduler->onAccessUpdate($node);
			}
		} catch (InvalidPathException|Exception|NotFoundException $e) {
			$this->logger->warning($e->getMessage(), ['exception' => $e]);
		}
	}


}
