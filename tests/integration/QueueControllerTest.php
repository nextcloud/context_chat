<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Tests;

use OCA\ContextChat\Controller\QueueController;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Db\QueueMapper;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\QueueService;
use OCA\ContextChat\Service\StorageService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueueControllerTest extends TestCase {
	public function testDocumentIsResolvedUsingLaterMount(): void {
		$document = new QueueFile();
		$document->setId(7);
		$document->setFileId(42);
		$document->setStorageId(3);

		$queueMapper = $this->createMock(QueueMapper::class);
		$queueMapper->expects($this->once())
			->method('getFromQueue')
			->with(1)
			->willReturn([$document]);
		$queueMapper->expects($this->once())
			->method('lock')
			->with(7)
			->willReturn(true);
		$queueMapper->expects($this->never())
			->method('delete');

		$contentItemMapper = $this->createMock(QueueContentItemMapper::class);
		$contentItemMapper->expects($this->once())
			->method('getFromQueue')
			->with(1)
			->willReturn([]);

		$wrongMount = $this->createMock(ICachedMountInfo::class);
		$wrongUser = $this->createMock(IUser::class);
		$wrongUser->method('getUID')->willReturn('wrong-user');
		$wrongMount->method('getUser')->willReturn($wrongUser);

		$validMount = $this->createMock(ICachedMountInfo::class);
		$validUser = $this->createMock(IUser::class);
		$validUser->method('getUID')->willReturn('valid-user');
		$validMount->method('getUser')->willReturn($validUser);

		$userMountCache = $this->createMock(IUserMountCache::class);
		$userMountCache->expects($this->once())
			->method('getMountsForStorageId')
			->with(3)
			->willReturn([$wrongMount, $validMount]);

		$wrongFolder = $this->createMock(Folder::class);
		$wrongFolder->expects($this->once())
			->method('getFirstNodeById')
			->with(42)
			->willReturn(null);

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(42);
		$file->method('getInternalPath')->willReturn('files/Test-Text.md');
		$file->method('getMTime')->willReturn(1234567890);
		$file->method('getMimeType')->willReturn('text/markdown');
		$file->method('getSize')->willReturn(123);

		$validFolder = $this->createMock(Folder::class);
		$validFolder->expects($this->once())
			->method('getFirstNodeById')
			->with(42)
			->willReturn($file);

		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->expects($this->exactly(2))
			->method('getUserFolder')
			->willReturnMap([
				['wrong-user', $wrongFolder],
				['valid-user', $validFolder],
			]);

		$storageService = $this->createMock(StorageService::class);
		$storageService->expects($this->once())
			->method('getUsersForFileId')
			->with(42)
			->willReturn(['valid-user']);

		$controller = new QueueController(
			'context_chat',
			$this->createMock(IRequest::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(IAppConfig::class),
			$this->createMock(QueueService::class),
			$storageService,
			$this->createMock(IJobList::class),
			$this->createMock(ITimeFactory::class),
			$queueMapper,
		);

		$response = $controller->getDocumentsQueueItems(
			$storageService,
			$rootFolder,
			$queueMapper,
			$contentItemMapper,
			$userMountCache,
			1,
		);

		$data = $response->getData();
		$this->assertEquals([
			'userIds' => ['valid-user'],
			'reference' => ProviderConfigService::getSourceId(42),
			'title' => 'files/Test-Text.md',
			'content' => null,
			'modified' => 1234567890,
			'type' => 'text/markdown',
			'provider' => ProviderConfigService::getDefaultProviderKey(),
			'size' => 123,
		], ((array)$data['files'])[7]->jsonSerialize());
	}
}
