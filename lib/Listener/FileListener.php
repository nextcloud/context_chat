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

	/**
	 * @var array<array-key, bool>
	 */
	private array $addedMounts = [];

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
			if ($event instanceof CacheEntryInsertedEvent && str_starts_with($event->getPath(), 'appdata_')) {
				return;
			}
			if ($event instanceof NodeWrittenEvent) {
				$node = $event->getNode();
				if (!$node instanceof File) {
					return;
				}
				// Synchronous, because we don't recurse
				$this->fsEventService->onInsert($node, false, true);
			}

			if ($event instanceof BeforeNodeDeletedEvent) {
				// Synchronous, because we wouldn't have the recursive list of file ids after deletion.
				// Folders need to be handled here too since cache entry is not removed when file is
				//   initially deleted (moved to trashbin) so NodeRemovedFromCache is not fired and not
				//   handled but we need to remove the file from the CCB index.
				$this->fsEventService->onDelete($event->getNode());
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
				// Asynchronous, because we potentially recurse.
				// Index the file again to update the metadata of the source in CCB
				// and the access list based on the new path.
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
				// Synchronous, because we wouldn't have the recursive list of file ids after deletion
				$this->fsEventService->onDelete($node);
			}

			if ($event instanceof \OCP\Files\Config\Event\UserMountAddedEvent) {
				$rootId = $event->mountPoint->getRootId();
				// Asynchronous, because we potentially recurse and this event needs to be handled fast
				$this->fsEventScheduler->onAccessUpdateDecl($rootId);
				// Remember that this mount was added in the current process (see UserMountRemovedEvent below)
				$this->addedMounts[$event->mountPoint->getUser()->getUID() . '-' . $rootId] = true;
			}

			if ($event instanceof \OCP\Files\Config\Event\UserMountRemovedEvent) {
				// If we just added this mount, ignore the removal, as the 'removal' event is always fired after
				// the 'added' event in server
				$rootId = $event->mountPoint->getRootId();
				$mountKey = $event->mountPoint->getUser()->getUID() . '-' . $rootId;
				if (array_key_exists($mountKey, $this->addedMounts) && $this->addedMounts[$mountKey] === true) {
					return;
				}
				// Asynchronous, because we potentially recurse and this event needs to be handled fast
				$this->fsEventScheduler->onAccessUpdateDecl($rootId);
			}
		} catch (InvalidPathException|Exception|NotFoundException $e) {
			$this->logger->warning('Error in fs event listener: ' . $e->getMessage(), ['exception' => $e]);
		}
	}
}
