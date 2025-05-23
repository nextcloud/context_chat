<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2022 The Recognize contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Listener;

use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\FsEventService;
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

/**
 * @template-implements IEventListener<Event>
 */
class FileListener implements IEventListener {

	public function __construct(
		private Logger $logger,
		private IRootFolder $rootFolder,
		private FsEventService $fsEventService,
	) {
	}

	public function handle(Event $event): void {
		try {
			if ($event instanceof NodeWrittenEvent) {
				$node = $event->getNode();
				if (!$node instanceof File) {
					return;
				}
				$this->fsEventService->onInsert($node, false, true);
			}

			if ($event instanceof BeforeNodeDeletedEvent) {
				$this->fsEventService->onDelete($event->getNode(), false);
				return;
			}

			if ($event instanceof NodeCreatedEvent) {
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
				$this->fsEventService->onInsert($node);
				return;
			}

			// This event is also used for moves
			if ($event instanceof NodeRenamedEvent) {
				$targetNode = $event->getTarget();
				$this->fsEventService->onAccessUpdate($targetNode);
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
				$this->fsEventService->onDelete($node);
			}

			if ($event instanceof \OCP\Files\Config\Event\UserMountAddedEvent) {
				$node = $event->mountPoint->getMountPointNode();
				if ($node === null) {
					return;
				}
				$this->fsEventService->onInsert($node);
			}

			if ($event instanceof \OCP\Files\Config\Event\UserMountRemovedEvent) {
				$node = $event->mountPoint->getMountPointNode();
				if ($node === null) {
					return;
				}
				$this->fsEventService->onDelete($node);
			}
		} catch (InvalidPathException $e) {
			$this->logger->warning($e->getMessage(), ['exception' => $e]);
		}
	}


}
