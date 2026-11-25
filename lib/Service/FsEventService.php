<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OC\Files\Storage\Wrapper\Jail;
use OC\Files\Storage\Wrapper\Wrapper;
use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Logger;
use OCP\DB\Exception;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Cache\ICacheEvent;
use OCP\Files\Cache\IFileAccess;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IHomeStorage;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;

class FsEventService {
	// Ids of groupfolders' separate storages. Their classes are plain Local/ObjectStoreStorage, shared with
	// external and primary storages, so only the id tells them apart:
	// https://github.com/nextcloud/groupfolders/blob/abf7d207c64425342bd80c7bddee0c75f27ca458/lib/Mount/FolderStorageManager.php#L148-L162
	// https://github.com/nextcloud/groupfolders/blob/abf7d207c64425342bd80c7bddee0c75f27ca458/lib/Mount/FolderStorageManager.php#L190
	private const GROUPFOLDER_STORAGE_ID_REGEX = '#^(local::.+/__groupfolders/\d+/|object::store:object::groupfolder:\d+\.)#';

	private ?int $rootStorageId = null;

	public function __construct(
		private Logger $logger,
		private QueueService $queue,
		private ActionScheduler $actionService,
		private StorageService $storageService,
		private IMimeTypeDetector $mimeTypeDetector,
		private IRootFolder $rootFolder,
		private IFileAccess $fileAccess,
	) {

	}

	public function onAccessUpdateDecl(Node $node, bool $recurse = true): void {
		if ($node instanceof Folder) {
			if (!$recurse) {
				return;
			}

			$fileIds = $this->storageService->getAllFilesInFolder($node);
		} else {
			if (!$this->allowedMimeType($node->getMimetype())) {
				return;
			}
			try {
				$fileIds = [$node->getId()];
			} catch (InvalidPathException|NotFoundException $e) {
				return;
			}
		}
		foreach ($fileIds as $fileId) {
			try {
				$fileRef = ProviderConfigService::getSourceId($fileId);
				$userIds = $this->storageService->getUsersForFileId($fileId);

				$this->actionService->updateAccessDeclSource($userIds, $fileRef);
			} catch (InvalidPathException|NotFoundException $e) {
				$this->logger->warning('Cannot get file id for declarative access update:' . $e->getMessage(), [
					'exception' => $e
				]);
			} catch (Exception $e) {
				$this->logger->warning('Failed to insert declarative access update into DB:' . $e->getMessage(), [
					'exception' => $e
				]);
			}
		}
	}

	public function onDelete(Node $node, bool $recurse = true): void {
		if ($node instanceof Folder) {
			if (!$recurse) {
				return;
			}
			$fileIds = $this->storageService->getAllFilesInFolder($node);
		} else {
			if (!$this->allowedMimeType($node->getMimetype())) {
				return;
			}
			try {
				$fileIds = [$node->getId()];
			} catch (InvalidPathException|NotFoundException $e) {
				return;
			}
		}

		$fileRefs = [];
		foreach ($fileIds as $fileId) {
			$fileRefs[] = ProviderConfigService::getSourceId($fileId);
		}
		try {
			$this->actionService->deleteSources($fileRefs);
		} catch (Exception $e) {
			$this->logger->error(
				'Failed to remove files from the backend',
				['exception' => $e, 'fileRefs' => $fileRefs],
			);
		}
	}

	/**
	 * @param list<int> $fileIds
	 * @return void
	 */
	public function onDeleteCacheEntries(array $fileIds): void {
		if (count($fileIds) === 0) {
			return;
		}

		try {
			$this->queue->removeFileIdsFromQueue($fileIds);
		} catch (Exception $e) {
			$this->logger->error(
				'Failed to remove files from the indexing queue',
				['exception' => $e, 'fileIds' => $fileIds],
			);
		}

		$fileRefs = [];
		foreach ($fileIds as $fileId) {
			$fileRefs[] = ProviderConfigService::getSourceId($fileId);
		}
		try {
			$this->actionService->deleteSources($fileRefs);
		} catch (Exception $e) {
			$this->logger->error(
				'Failed to remove files from the backend',
				['exception' => $e, 'fileIds' => $fileIds],
			);
		}
	}

	public function onInsert(Node $node, bool $recurse = true, bool $update = false): void {
		if (!$this->allowedPath($node->getPath())) {
			return;
		}
		if ($node instanceof Folder) {
			if (!$recurse) {
				return;
			}
			$fileIds = $this->storageService->getAllFilesInFolder($node);
		} else {
			if (!$this->allowedMimeType($node->getMimetype())) {
				return;
			}
			try {
				$fileIds = [$node->getId()];
			} catch (InvalidPathException|NotFoundException $e) {
				return;
			}
		}

		foreach ($fileIds as $fileId) {
			$queueFile = new QueueFile();
			if ($node->getMountPoint()->getNumericStorageId() === null) {
				return;
			}
			$queueFile->setStorageId($node->getMountPoint()->getNumericStorageId());
			$queueFile->setRootId($node->getMountPoint()->getStorageRootId());
			$queueFile->setFileId($fileId);
			$queueFile->setUpdate($update);
			try {
				$this->queue->insertIntoQueue($queueFile);
			} catch (Exception $e) {
				$this->logger->error('Failed to add file to queue', ['exception' => $e]);
			}
		}
	}

	public function isIndexableCacheEntry(ICacheEvent $event): bool {
		// folders are detected as "application/octet-stream" which is fine for this use-case
		// since they are not indexable either, the files inside the folder are received in
		// separate cache entry events
		if (!$this->allowedMimeType($this->mimeTypeDetector->detectPath($event->getPath()))) {
			return false;
		}
		return $this->allowedCacheEventPath($event);
	}

	public function allowedMimeType(string $mimeType): bool {
		return in_array($mimeType, Application::MIMETYPES, true);
	}

	/**
	 * Cheap check before scheduling a node: folders pass, their children may be indexable.
	 */
	public function isIndexableNode(Node $node): bool {
		return $this->allowedPath($node->getPath())
			&& (!$node instanceof File || $this->allowedMimeType($node->getMimetype()));
	}

	/**
	 * Whether the file system node at an absolute path ("/userId/files_versions/...") may be indexed.
	 */
	public function allowedPath(string $path): bool {
		return !preg_match('#^/[^/]+/(files_versions|files_trashbin)/.+#', $path);
	}

	/**
	 * Whether the path of a cache event may hold indexable files, judging by the layout
	 * of the storage the event was fired for.
	 */
	public function allowedCacheEventPath(ICacheEvent $event): bool {
		$storage = $event->getStorage();
		if ($storage->getCache()->getNumericStorageId() !== $event->getStorageId()) {
			// Removal half of a move to another storage. The event holds the target storage but the source's
			// path and storage id, so the path can't be checked against the layout of either storage.
			// https://github.com/nextcloud/server/blob/0ff6535942ddf0a2ab38c215c3d0ff78aa6f43f7/lib/private/Files/Cache/Cache.php#L877
			return $event->getPath() !== '';
		}

		$path = $event->getPath();
		if ($storage->instanceOfStorage(Jail::class)) {
			// The event path is relative to the storage below the jail, except for inserts into groupfolders,
			// which rewrites them relative to its jail. The filecache row has the path below the jail.
			// https://github.com/nextcloud/groupfolders/blob/abf7d207c64425342bd80c7bddee0c75f27ca458/lib/Listeners/CacheListener.php#L24-L44
			[$storage] = $this->unjail($storage, '');
			if (!$event instanceof CacheEntryRemovedEvent) {
				$entry = $this->fileAccess->getByFileIdInStorage($event->getFileId(), $event->getStorageId());
				if ($entry === null) {
					return false;
				}
				$path = $entry->getPath();
			}
		}
		return $this->allowedLayoutPath($storage, $path);
	}

	/**
	 * Whether a path inside a storage may hold indexable files.
	 *
	 * @param string $path path inside the storage as the storage presents it (jailed for a jail), "" is its root
	 */
	public function allowedStoragePath(IStorage $storage, string $path): bool {
		return $this->allowedLayoutPath(...$this->unjail($storage, $path));
	}

	/**
	 * @return array{IStorage, string} the storage below all jails and the path in it
	 */
	private function unjail(IStorage $storage, string $path): array {
		while ($storage->instanceOfStorage(Jail::class)) {
			// instanceOfStorage() is true for a wrapper around a jail too, so find the jail itself
			/** @var Wrapper $storage */
			$jail = $storage->getInstanceOfStorage(Jail::class);
			if ($jail === null) {
				break;
			}
			$path = $jail->getUnjailedPath($path);
			$storage = $jail->getUnjailedStorage();
		}
		return [$storage, $path];
	}

	/**
	 * Only storages with a known layout are restricted; anything else (external storages) is user
	 * content, where any folder name is valid.
	 *
	 * @param IStorage $storage not a jail
	 * @param string $path path inside the storage, "" is its root
	 */
	private function allowedLayoutPath(IStorage $storage, string $path): bool {
		if ($path === '') {
			return false;
		}

		if ($storage->instanceOfStorage(IHomeStorage::class)) {
			// only "files/"; not "files_versions/", "files_trashbin/", "uploads/", "cache/", ...
			return str_starts_with($path, 'files/');
		}

		if (preg_match(self::GROUPFOLDER_STORAGE_ID_REGEX, (string)$storage->getId()) === 1) {
			// layout: "files/", "trash/", "versions/"
			return preg_match('#^(trash|versions)(/|$)#', $path) !== 1;
		}

		// Root storage: "appdata_<instanceid>/" and groupfolders without a separate storage
		// ("__groupfolders/<id>/", "__groupfolders/{trash,versions}/<id>/"). The numeric id is looked up,
		// it differs per install.
		// https://github.com/nextcloud/server/blob/0ff6535942ddf0a2ab38c215c3d0ff78aa6f43f7/lib/private/Files/Cache/LocalRootScanner.php#L54
		// https://github.com/nextcloud/groupfolders/blob/abf7d207c64425342bd80c7bddee0c75f27ca458/lib/Mount/FolderStorageManager.php#L210-L277
		if ($storage->getCache()->getNumericStorageId() === $this->getRootStorageId()) {
			return preg_match('#^__groupfolders/\d+/#', $path) === 1;
		}

		return true;
	}

	private function getRootStorageId(): ?int {
		return $this->rootStorageId ??= $this->rootFolder->getMount('/')->getNumericStorageId();
	}
}
