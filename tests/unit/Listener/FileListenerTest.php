<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Tests\Listener;

use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Listener\FileListener;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\ActionScheduler;
use OCA\ContextChat\Service\FsEventScheduler;
use OCA\ContextChat\Service\FsEventService;
use OCA\ContextChat\Service\QueueService;
use OCA\ContextChat\Service\StorageService;
use OCP\Files\Cache\CacheEntryInsertedEvent;
use OCP\Files\Cache\CacheEntryRemovedEvent;
use OCP\Files\Cache\ICache;
use OCP\Files\Cache\ICacheEntry;
use OCP\Files\Events\NodeRemovedFromCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Cache events are fired synchronously inside the scanner for every entry, so the listener
 * must filter on the event's own path and storage cache entry before resolving the node
 * through IRootFolder::getFirstNodeById(), which is expensive
 * (https://github.com/nextcloud/context_chat/issues/297).
 */
class FileListenerTest extends TestCase {
	private const STORAGE_ID = 42;
	private const ROOT_ID = 7;

	private IRootFolder&MockObject $rootFolder;
	private QueueService&MockObject $queueService;
	private ActionScheduler&MockObject $actionScheduler;
	private StorageService&MockObject $storageService;
	private FsEventService $fsEventService;
	private FileListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$logger = $this->createMock(Logger::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->queueService = $this->createMock(QueueService::class);
		$this->actionScheduler = $this->createMock(ActionScheduler::class);
		$this->storageService = $this->createMock(StorageService::class);
		$mimeTypeDetector = $this->createMock(IMimeTypeDetector::class);  // extension based, like the server's detector
		$mimeTypeDetector->method('detectPath')->willReturnCallback(fn (string $path) => match (pathinfo($path, PATHINFO_EXTENSION)) {
			'md' => 'text/markdown',
			'pdf' => 'application/pdf',
			'jpg' => 'image/jpeg',
			default => 'application/octet-stream',
		});

		$this->fsEventService = new FsEventService(
			$logger,
			$this->queueService,
			$this->actionScheduler,
			$this->storageService,
			$mimeTypeDetector,
		);
		$this->listener = new FileListener(
			$logger,
			$this->rootFolder,
			$this->fsEventService,
			$this->createMock(FsEventScheduler::class),
		);
	}

	private function storageWithEntry(string $path, int $fileId, string $mimetype): IStorage {
		$cacheEntry = $this->createMock(ICacheEntry::class);
		$cacheEntry->method('getId')->willReturn($fileId);
		$cacheEntry->method('getMimeType')->willReturn($mimetype);
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->with($path)->willReturn($cacheEntry);
		$storage = $this->createMock(IStorage::class);
		$storage->method('getCache')->willReturn($cache);
		return $storage;
	}

	private function mountPoint(): IMountPoint {
		$mountPoint = $this->createMock(IMountPoint::class);
		$mountPoint->method('getNumericStorageId')->willReturn(self::STORAGE_ID);
		$mountPoint->method('getStorageRootId')->willReturn(self::ROOT_ID);
		return $mountPoint;
	}

	private function file(int $fileId, string $path, string $mimetype): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn($fileId);
		$file->method('getPath')->willReturn($path);
		$file->method('getMimetype')->willReturn($mimetype);
		$file->method('getMountPoint')->willReturn($this->mountPoint());
		return $file;
	}

	public function testInsertedSupportedFileIsQueued(): void {
		$path = 'files/documentation/readme.md';
		$event = new CacheEntryInsertedEvent($this->storageWithEntry($path, 4243, 'text/markdown'), $path, 4243, self::STORAGE_ID);
		$file = $this->file(4243, '/admin/files/documentation/readme.md', 'text/markdown');

		$this->rootFolder->expects($this->once())->method('getFirstNodeById')->with(4243)->willReturn($file);
		$this->queueService->expects($this->once())->method('insertIntoQueue')
			->with($this->callback(fn (QueueFile $queueFile) => $queueFile->getFileId() === 4243
				&& $queueFile->getStorageId() === self::STORAGE_ID
				&& $queueFile->getRootId() === self::ROOT_ID
				&& $queueFile->getUpdate() === false));

		$this->listener->handle($event);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function skippedInsertProvider(): array {
		return [
			'directory' => ['files/documentation/chapter1', ICacheEntry::DIRECTORY_MIMETYPE],
			'unsupported mimetype' => ['files/photo.jpg', 'image/jpeg'],
			// the mimetype of versions is detected without the .v<timestamp> suffix
			'file version' => ['files_versions/documentation/readme.pdf.v1700000000', 'application/pdf'],
			'trashbin' => ['files_trashbin/files/readme.pdf.d1700000000', 'application/pdf'],
		];
	}

	#[DataProvider('skippedInsertProvider')]
	public function testInsertedEntryIsSkippedWithoutNodeResolution(string $path, string $mimetype): void {
		$event = new CacheEntryInsertedEvent($this->storageWithEntry($path, 4242, $mimetype), $path, 4242, self::STORAGE_ID);

		$this->rootFolder->expects($this->never())->method('getFirstNodeById');
		$this->queueService->expects($this->never())->method('insertIntoQueue');

		$this->listener->handle($event);
	}

	public function testInsertedAppdataEntryIsSkippedWithoutCacheLookup(): void {
		$storage = $this->createMock(IStorage::class);
		$storage->expects($this->never())->method('getCache');
		$event = new CacheEntryInsertedEvent($storage, 'appdata_oc123/preview/1/2.png', 4242, self::STORAGE_ID);

		$this->rootFolder->expects($this->never())->method('getFirstNodeById');
		$this->queueService->expects($this->never())->method('insertIntoQueue');

		$this->listener->handle($event);
	}

	public function testRemovedCacheEntryIsDroppedFromQueue(): void {
		$event = new CacheEntryRemovedEvent($this->createMock(IStorage::class), 'files/readme.md', 4243, self::STORAGE_ID);

		$this->queueService->expects($this->once())->method('removeFileIdsFromQueue')->with([4243]);
		$this->actionScheduler->expects($this->once())->method('deleteSources')->with(['files__default: 4243']);

		$this->listener->handle($event);
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function skippedRemovalProvider(): array {
		return [
			'folder' => ['files/documentation'],
			'unsupported mimetype' => ['files/photo.jpg'],
			'appdata' => ['appdata_oc123/preview/1/2.md'],
			'trashbin' => ['files_trashbin/files/readme.md.d1700000000'],
		];
	}

	#[DataProvider('skippedRemovalProvider')]
	public function testRemovedCacheEntryIsSkipped(string $path): void {
		$event = new CacheEntryRemovedEvent($this->createMock(IStorage::class), $path, 4243, self::STORAGE_ID);

		$this->queueService->expects($this->never())->method('removeFileIdsFromQueue');
		$this->actionScheduler->expects($this->never())->method('deleteSources');

		$this->listener->handle($event);
	}

	public function testBatchedRemovalEventIsFiltered(): void {
		if (!class_exists(\OCP\Files\Cache\CacheEntriesRemovedEvent::class)) {
			require_once __DIR__ . '/../../../stubs/cache-entries-removed-event.php';
		}
		$storage = $this->createMock(IStorage::class);
		$event = new \OCP\Files\Cache\CacheEntriesRemovedEvent([
			new CacheEntryRemovedEvent($storage, 'files/docs', 1, self::STORAGE_ID),
			new CacheEntryRemovedEvent($storage, 'files/docs/readme.md', 2, self::STORAGE_ID),
			new CacheEntryRemovedEvent($storage, 'files/docs/photo.jpg', 3, self::STORAGE_ID),
			new CacheEntryRemovedEvent($storage, 'files/docs/spec.pdf', 4, self::STORAGE_ID),
		]);

		$this->queueService->expects($this->once())->method('removeFileIdsFromQueue')->with([2, 4]);

		$this->listener->handle($event);
	}

	public function testNodeRemovedFromCacheWithUnsupportedMimetypeIsSkipped(): void {
		$path = 'files/photo.jpg';
		$event = new NodeRemovedFromCache($this->storageWithEntry($path, 4242, 'image/jpeg'), $path);

		$this->rootFolder->expects($this->never())->method('getFirstNodeById');
		$this->actionScheduler->expects($this->never())->method('deleteSources');

		$this->listener->handle($event);
	}

	public function testNodeRemovedFromCacheForFolderDeletesContainedFiles(): void {
		$path = 'files/documentation';
		$event = new NodeRemovedFromCache($this->storageWithEntry($path, 4242, ICacheEntry::DIRECTORY_MIMETYPE), $path);
		$folder = $this->createMock(Folder::class);

		$this->rootFolder->expects($this->once())->method('getFirstNodeById')->with(4242)->willReturn($folder);
		$this->storageService->method('getAllFilesInFolder')->with($folder)->willReturn((function () {
			yield 1;
			yield 2;
		})());
		$this->actionScheduler->expects($this->once())->method('deleteSources')
			->with($this->callback(fn (array $refs) => count($refs) === 2
				&& str_ends_with($refs[0], ': 1') && str_ends_with($refs[1], ': 2')));

		$this->listener->handle($event);
	}
}
