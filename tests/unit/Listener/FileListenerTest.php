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
use OCP\Files\IHomeStorage;
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
 *
 * The layout of the path depends on the storage: "files_versions/" is internal to a home
 * storage but a user's folder on an external storage. The storages are mocked here, the
 * integration test in tests/integration/FileListenerStoragesTest.php runs against the real ones.
 */
class FileListenerTest extends TestCase {
	private const STORAGE_ID = 42;
	private const ROOT_STORAGE_ID = 1;
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
		$rootMount = $this->createMock(IMountPoint::class);
		$rootMount->method('getNumericStorageId')->willReturn(self::ROOT_STORAGE_ID);
		$this->rootFolder->method('getMount')->with('/')->willReturn($rootMount);
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
			$this->rootFolder,
		);
		$this->listener = new FileListener(
			$logger,
			$this->rootFolder,
			$this->fsEventService,
			$this->createMock(FsEventScheduler::class),
		);
	}

	/**
	 * @param string $kind one of home, groupfolder, root, external or jail
	 * @param array<string, array{int, string}> $entries cache entries by path: [file id, mimetype]
	 */
	private function storage(string $kind, array $entries = [], ?int $numericId = null): IStorage {
		$cache = $this->createMock(ICache::class);
		$cache->method('getNumericStorageId')->willReturn($numericId ?? ($kind === 'root' ? self::ROOT_STORAGE_ID : self::STORAGE_ID));
		$cache->method('get')->willReturnCallback(function (string $path) use ($entries) {
			if (!isset($entries[$path])) {
				return false;
			}
			$cacheEntry = $this->createMock(ICacheEntry::class);
			$cacheEntry->method('getId')->willReturn($entries[$path][0]);
			$cacheEntry->method('getMimeType')->willReturn($entries[$path][1]);
			return $cacheEntry;
		});

		$id = match ($kind) {
			'home' => 'home::admin',
			'groupfolder' => 'local::/var/www/html/data/__groupfolders/7/',
			'root' => 'local::/var/www/html/data/',
			'jail' => 'local::/var/www/html/data/__groupfolders/7/',
			default => 'smb::server/share//',
		};
		$storage = $this->createMock(IStorage::class);
		$storage->method('getCache')->willReturn($cache);
		$storage->method('getId')->willReturn($id);
		$storage->method('instanceOfStorage')->willReturnCallback(fn (string $class) => match ($class) {
			IHomeStorage::class => $kind === 'home',
			'OC\Files\Storage\Wrapper\Jail' => $kind === 'jail',
			default => false,
		});
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
		$event = new CacheEntryInsertedEvent($this->storage('home', [$path => [4243, 'text/markdown']]), $path, 4243, self::STORAGE_ID);
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
	 * Paths that are indexable or not depending on the storage they are on.
	 * [storage kind, path, indexable]
	 *
	 * @return array<string, array{string, string, bool}>
	 */
	public static function storagePathProvider(): array {
		return [
			'home: user file' => ['home', 'files/documentation/readme.md', true],
			'home: version' => ['home', 'files_versions/documentation/readme.md.v1700000000', false],
			'home: trashbin' => ['home', 'files_trashbin/files/readme.md.d1700000000', false],
			'home: not the files folder' => ['home', 'uploads/readme.md', false],
			// groupfolders with a separate storage
			'groupfolder: file' => ['groupfolder', 'files/documentation/readme.md', true],
			'groupfolder: trash' => ['groupfolder', 'trash/readme.md.d1700000000', false],
			'groupfolder: trashed folder content' => ['groupfolder', 'trash/documentation.d1700000000/readme.md', false],
			'groupfolder: versions' => ['groupfolder', 'versions/4242/readme.md', false],
			// groupfolders without a separate storage, in the root storage
			'root: groupfolder file' => ['root', '__groupfolders/7/documentation/readme.md', true],
			'root: groupfolder trash' => ['root', '__groupfolders/trash/7/readme.md.d1700000000', false],
			'root: groupfolder versions' => ['root', '__groupfolders/versions/7/4242/readme.md', false],
			'root: appdata' => ['root', 'appdata_oc123/preview/1/readme.md', false],
			// external storages are the user's own content, whatever the folders are called
			'external: file' => ['external', 'documentation/readme.md', true],
			'external: trash folder' => ['external', 'trash/readme.md', true],
			'external: versions folder' => ['external', 'versions/readme.md', true],
			'external: files_versions folder' => ['external', 'files_versions/readme.md', true],
			'external: files_trashbin folder' => ['external', 'files_trashbin/readme.md', true],
			'external: groupfolders look-alike' => ['external', '__groupfolders/trash/readme.md', true],
			'external: appdata look-alike' => ['external', 'appdata_oc123/readme.md', true],
		];
	}

	#[DataProvider('storagePathProvider')]
	public function testInsertedEntryDependsOnTheStorageLayout(string $kind, string $path, bool $indexable): void {
		$storage = $this->storage($kind, [$path => [4243, 'text/markdown']]);
		$event = new CacheEntryInsertedEvent($storage, $path, 4243, $storage->getCache()->getNumericStorageId());

		if ($indexable) {
			$this->rootFolder->expects($this->once())->method('getFirstNodeById')->with(4243)
				->willReturn($this->file(4243, '/admin/files/' . $path, 'text/markdown'));
			$this->queueService->expects($this->once())->method('insertIntoQueue');
		} else {
			$this->rootFolder->expects($this->never())->method('getFirstNodeById');
			$this->queueService->expects($this->never())->method('insertIntoQueue');
		}

		$this->listener->handle($event);
	}

	#[DataProvider('storagePathProvider')]
	public function testRemovedEntryDependsOnTheStorageLayout(string $kind, string $path, bool $indexable): void {
		$storage = $this->storage($kind);
		$event = new CacheEntryRemovedEvent($storage, $path, 4243, $storage->getCache()->getNumericStorageId());

		$this->queueService->expects($indexable ? $this->once() : $this->never())->method('removeFileIdsFromQueue');
		$this->actionScheduler->expects($indexable ? $this->once() : $this->never())->method('deleteSources');

		$this->listener->handle($event);
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function skippedInsertProvider(): array {
		return [
			'directory' => ['files/documentation/chapter1', ICacheEntry::DIRECTORY_MIMETYPE],
			'unsupported mimetype' => ['files/photo.jpg', 'image/jpeg'],
		];
	}

	#[DataProvider('skippedInsertProvider')]
	public function testInsertedEntryIsSkippedWithoutNodeResolution(string $path, string $mimetype): void {
		$event = new CacheEntryInsertedEvent($this->storage('home', [$path => [4242, $mimetype]]), $path, 4242, self::STORAGE_ID);

		$this->rootFolder->expects($this->never())->method('getFirstNodeById');
		$this->queueService->expects($this->never())->method('insertIntoQueue');

		$this->listener->handle($event);
	}

	public function testInsertedAppdataEntryIsSkippedWithoutCacheLookup(): void {
		$storage = $this->storage('root');
		$event = new CacheEntryInsertedEvent($storage, 'appdata_oc123/preview/1/2.png', 4242, self::ROOT_STORAGE_ID);

		$this->rootFolder->expects($this->never())->method('getFirstNodeById');
		$this->queueService->expects($this->never())->method('insertIntoQueue');

		$this->listener->handle($event);
	}

	public function testRemovedCacheEntryIsDroppedFromQueue(): void {
		$event = new CacheEntryRemovedEvent($this->storage('home'), 'files/readme.md', 4243, self::STORAGE_ID);

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
		];
	}

	#[DataProvider('skippedRemovalProvider')]
	public function testRemovedCacheEntryIsSkipped(string $path): void {
		$event = new CacheEntryRemovedEvent($this->storage('home'), $path, 4243, self::STORAGE_ID);

		$this->queueService->expects($this->never())->method('removeFileIdsFromQueue');
		$this->actionScheduler->expects($this->never())->method('deleteSources');

		$this->listener->handle($event);
	}

	/**
	 * A move to another storage reports the target storage with the path and id of the source
	 * storage, whose layout is not known, so only the mimetype can filter it.
	 */
	public function testRemovalByCrossStorageMoveIsNotFilteredByLayout(): void {
		$targetStorage = $this->storage('external', numericId: self::STORAGE_ID);
		$sourceStorageId = 99;
		$event = new CacheEntryRemovedEvent($targetStorage, 'files_versions/readme.md', 4243, $sourceStorageId);

		$this->queueService->expects($this->once())->method('removeFileIdsFromQueue')->with([4243]);

		$this->listener->handle($event);
	}

	public function testBatchedRemovalEventIsFiltered(): void {
		if (!class_exists(\OCP\Files\Cache\CacheEntriesRemovedEvent::class)) {
			require_once __DIR__ . '/../../../stubs/cache-entries-removed-event.php';
		}
		$storage = $this->storage('home');
		$event = new \OCP\Files\Cache\CacheEntriesRemovedEvent([
			new CacheEntryRemovedEvent($storage, 'files/docs', 1, self::STORAGE_ID),
			new CacheEntryRemovedEvent($storage, 'files/docs/readme.md', 2, self::STORAGE_ID),
			new CacheEntryRemovedEvent($storage, 'files/docs/photo.jpg', 3, self::STORAGE_ID),
			new CacheEntryRemovedEvent($storage, 'files/docs/spec.pdf', 4, self::STORAGE_ID),
			new CacheEntryRemovedEvent($storage, 'files_trashbin/files/spec.pdf.d1700000000', 5, self::STORAGE_ID),
		]);

		$this->queueService->expects($this->once())->method('removeFileIdsFromQueue')->with([2, 4]);

		$this->listener->handle($event);
	}

	public function testNodeRemovedFromCacheWithUnsupportedMimetypeIsSkipped(): void {
		$path = 'files/photo.jpg';
		$event = new NodeRemovedFromCache($this->storage('home', [$path => [4242, 'image/jpeg']]), $path);

		$this->rootFolder->expects($this->never())->method('getFirstNodeById');
		$this->actionScheduler->expects($this->never())->method('deleteSources');

		$this->listener->handle($event);
	}

	public function testNodeRemovedFromCacheOfInternalHomeFolderIsSkipped(): void {
		$path = 'files_trashbin/files/readme.md.d1700000000';
		$event = new NodeRemovedFromCache($this->storage('home', [$path => [4242, 'text/markdown']]), $path);

		$this->rootFolder->expects($this->never())->method('getFirstNodeById');
		$this->actionScheduler->expects($this->never())->method('deleteSources');

		$this->listener->handle($event);
	}

	public function testNodeRemovedFromCacheOnExternalStorageKeepsFoldersNamedLikeInternalOnes(): void {
		$path = 'trash/readme.md';
		$event = new NodeRemovedFromCache($this->storage('external', [$path => [4242, 'text/markdown']]), $path);
		$file = $this->file(4242, '/admin/files/ext/trash/readme.md', 'text/markdown');

		$this->rootFolder->expects($this->once())->method('getFirstNodeById')->with(4242)->willReturn($file);
		$this->actionScheduler->expects($this->once())->method('deleteSources')->with(['files__default: 4242']);

		$this->listener->handle($event);
	}

	/**
	 * The storage of a groupfolder mount is a jail, the path is relative to it.
	 */
	public function testNodeRemovedFromCacheOnJailedStorageIsNotFilteredByLayout(): void {
		$path = 'trash/readme.md';
		$event = new NodeRemovedFromCache($this->storage('jail', [$path => [4242, 'text/markdown']]), $path);
		$file = $this->file(4242, '/admin/files/gf/trash/readme.md', 'text/markdown');

		$this->rootFolder->expects($this->once())->method('getFirstNodeById')->with(4242)->willReturn($file);
		$this->actionScheduler->expects($this->once())->method('deleteSources')->with(['files__default: 4242']);

		$this->listener->handle($event);
	}

	public function testNodeRemovedFromCacheForFolderDeletesContainedFiles(): void {
		$path = 'files/documentation';
		$event = new NodeRemovedFromCache($this->storage('home', [$path => [4242, ICacheEntry::DIRECTORY_MIMETYPE]]), $path);
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
