<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Logger;
use OCP\DB\Exception;
use OCP\Files\Cache\ICacheEvent;
use OCP\Files\Folder;
use OCP\Files\IHomeStorage;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;

class FsEventService {
	// ids of the storages groupfolders creates for a folder with a separate storage
	private const GROUPFOLDER_STORAGE_ID_REGEX = '#^(local::.+/__groupfolders/\d+/|object::store:object::groupfolder:\d+\.)#';

	private ?int $rootStorageId = null;

	public function __construct(
		private Logger $logger,
		private QueueService $queue,
		private ActionScheduler $actionService,
		private StorageService $storageService,
		private IMimeTypeDetector $mimeTypeDetector,
		private IRootFolder $rootFolder,
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
			// a move across storages reports the target storage with the path and id of the
			// source, so the layout of the source storage is unknown
			return $event->getPath() !== '';
		}
		return $this->allowedStoragePath($storage, $event->getPath());
	}

	/**
	 * Whether a path inside a storage may hold indexable files, judging by the layout of that
	 * storage. Only the storages whose layout is known are restricted, everything else (external
	 * storages) is user content, in which any folder name is valid.
	 *
	 * @param string $path path inside the storage, "" is the storage root
	 */
	public function allowedStoragePath(IStorage $storage, string $path): bool {
		if ($path === '') {
			return false;
		}

		// NodeRemovedFromCache reports the storage of the mount, which is a jail for groupfolders, so
		// the path is relative to the jail root and tells nothing about the layout of the storage
		if ($storage->instanceOfStorage('OC\Files\Storage\Wrapper\Jail')) {
			return true;
		}

		if ($storage->instanceOfStorage(IHomeStorage::class)) {
			// "files/" is the only user content, next to "files_versions/", "files_trashbin/", "cache/", ...
			return str_starts_with($path, 'files/');
		}

		if (preg_match(self::GROUPFOLDER_STORAGE_ID_REGEX, (string)$storage->getId()) === 1) {
			// "files/", "trash/" and "versions/"
			return preg_match('#^(trash|versions)(/|$)#', $path) !== 1;
		}

		if ($storage->getCache()->getNumericStorageId() === $this->getRootStorageId()) {
			// next to "appdata_<instanceid>/", groupfolders without a separate storage live in
			// "__groupfolders/<id>/", "__groupfolders/trash/<id>/" and "__groupfolders/versions/<id>/"
			return preg_match('#^__groupfolders/\d+/#', $path) === 1;
		}

		return true;
	}

	private function getRootStorageId(): ?int {
		return $this->rootStorageId ??= $this->rootFolder->getMount('/')->getNumericStorageId();
	}
}
