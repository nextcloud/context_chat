<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Tests\Listener;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Listener\TrashRestoreListener;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\ActionScheduler;
use OCA\ContextChat\Service\FsEventScheduler;
use OCA\ContextChat\Service\FsEventService;
use OCA\ContextChat\Service\QueueService;
use OCA\ContextChat\Service\StorageService;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\Files\Cache\IFileAccess;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IMimeTypeDetector;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The post_restore hook gives the path of the restored item in the files folder of the restoring user.
 * The restore through the real trash backends is in tests/integration/FileListenerStoragesTest.php.
 */
class TrashRestoreListenerTest extends TestCase {
	private IRootFolder&MockObject $rootFolder;
	private IUserSession&MockObject $userSession;
	private Folder&MockObject $userFolder;
	private FsEventScheduler&MockObject $fsEventScheduler;
	private TrashRestoreListener $listener;

	protected function setUp(): void {
		parent::setUp();
		$logger = $this->createMock(Logger::class);
		$this->rootFolder = $this->createMock(IRootFolder::class);
		$this->userFolder = $this->createMock(Folder::class);
		$this->rootFolder->method('getUserFolder')->with('admin')->willReturn($this->userFolder);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userSession->method('getUser')->willReturn($user);

		$fsEventService = new FsEventService(
			$logger,
			$this->createMock(QueueService::class),
			$this->createMock(ActionScheduler::class),
			$this->createMock(StorageService::class),
			$this->createMock(IMimeTypeDetector::class),
			$this->rootFolder,
			$this->createMock(IFileAccess::class),
		);
		$this->listener = new TrashRestoreListener(
			$logger,
			$this->rootFolder,
			$this->userSession,
			$fsEventService,
			$this->fsEventScheduler = $this->createMock(FsEventScheduler::class),
		);
	}

	private function restored(string $filePath, Node $node): void {
		$this->userFolder->method('get')->with($filePath)->willReturn($node);
	}

	public function testBootConnectsThePostRestoreHook(): void {
		$signal = '\OCA\Files_Trashbin\Trashbin';
		try {
			(new Application())->boot($this->createMock(IBootContext::class));

			$this->assertContains(
				['class' => TrashRestoreListener::class, 'name' => 'postRestoreHook'],
				\OC_Hook::getHooks()[$signal]['post_restore'] ?? [],
			);
		} finally {
			\OC_Hook::clear($signal, 'post_restore');
		}
	}

	public function testRestoredFolderIsScheduled(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/admin/files/docs');
		$this->restored('/docs', $folder);

		$this->fsEventScheduler->expects($this->once())->method('onInsert')->with($folder);

		$this->listener->onRestore(['filePath' => '/docs', 'trashPath' => '/docs.d1700000000']);
	}

	public function testRestoredSupportedFileIsScheduled(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/admin/files/readme.md');
		$file->method('getMimetype')->willReturn('text/markdown');
		$this->restored('/readme.md', $file);

		$this->fsEventScheduler->expects($this->once())->method('onInsert')->with($file);

		$this->listener->onRestore(['filePath' => '/readme.md', 'trashPath' => '/readme.md.d1700000000']);
	}

	public function testRestoredUnsupportedFileIsNotScheduled(): void {
		$file = $this->createMock(File::class);
		$file->method('getPath')->willReturn('/admin/files/photo.jpg');
		$file->method('getMimetype')->willReturn('image/jpeg');
		$this->restored('/photo.jpg', $file);

		$this->fsEventScheduler->expects($this->never())->method('onInsert');

		$this->listener->onRestore(['filePath' => '/photo.jpg', 'trashPath' => '/photo.jpg.d1700000000']);
	}

	public function testMissingNodeIsNotScheduled(): void {
		$this->userFolder->method('get')->willThrowException(new NotFoundException());

		$this->fsEventScheduler->expects($this->never())->method('onInsert');

		$this->listener->onRestore(['filePath' => '/gone.md', 'trashPath' => '/gone.md.d1700000000']);
	}

	public function testRestoreWithoutUserIsNotScheduled(): void {
		$userSession = $this->createMock(IUserSession::class);
		$userSession->method('getUser')->willReturn(null);
		$listener = new TrashRestoreListener(
			$this->createMock(Logger::class),
			$this->rootFolder,
			$userSession,
			$this->createMock(FsEventService::class),
			$this->fsEventScheduler,
		);

		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$this->fsEventScheduler->expects($this->never())->method('onInsert');

		$listener->onRestore(['filePath' => '/readme.md', 'trashPath' => '/readme.md.d1700000000']);
	}

	public function testRestoreWithoutPathIsNotScheduled(): void {
		$this->rootFolder->expects($this->never())->method('getUserFolder');
		$this->fsEventScheduler->expects($this->never())->method('onInsert');

		$this->listener->onRestore([]);
	}
}
