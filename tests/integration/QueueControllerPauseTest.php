<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Tests;

use OCA\ContextChat\Controller\QueueController;
use OCA\ContextChat\Db\QueueAction;
use OCA\ContextChat\Db\QueueActionMapper;
use OCA\ContextChat\Db\QueueContentItem;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Db\QueueMapper;
use OCA\ContextChat\Service\FetchGuard;
use OCA\ContextChat\Type\ActionType;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * What the endpoints the backend polls hand out while a wipe holds the files or everything back.
 */
#[Group('DB')]
class QueueControllerPauseTest extends TestCase {
	private IDBConnection $db;
	private FetchGuard $fetchGuard;
	/** @var list<array{0: string, 1: int}> */
	private array $pauses = [];

	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->fetchGuard = Server::get(FetchGuard::class);
		$this->clearQueues();

		$item = new QueueContentItem();
		$item->setItemId('item1');
		$item->setAppId('someapp');
		$item->setProviderId('prov');
		$item->setTitle('title');
		$item->setContent('content');
		$item->setDocumentType('text');
		$item->setLastModified(new \DateTime());
		$item->setUsers('admin');
		Server::get(QueueContentItemMapper::class)->insert($item);

		// a file that cannot be resolved: when it is handed out it is dropped from the queue
		$file = new QueueFile();
		$file->setFileId(PHP_INT_MAX >> 16);
		$file->setStorageId(1);
		$file->setRootId(1);
		$file->setUpdate(false);
		Server::get(QueueMapper::class)->insertIntoQueue($file);

		$action = new QueueAction();
		$action->setType(ActionType::DELETE_SOURCE_IDS);
		$action->setPayload('{"sourceIds":["someapp__prov: item1"]}');
		Server::get(QueueActionMapper::class)->insertIntoQueue($action);
	}

	protected function tearDown(): void {
		foreach ($this->pauses as $pause) {
			$this->fetchGuard->resume($pause);
		}
		$this->clearQueues();
		parent::tearDown();
	}

	private function clearQueues(): void {
		foreach (['context_chat_queue', 'context_chat_content_queue', 'context_chat_action_queue'] as $table) {
			$this->db->getQueryBuilder()->delete($table)->executeStatement();
		}
	}

	/**
	 * Arguments are resolved by type from the DI container, like the AppFramework dispatcher does.
	 *
	 * @param array<class-string, object> $services passed instead of the container's
	 * @return array<string, mixed>
	 */
	private function call(string $endpoint, array $services = []): array {
		$controller = Server::get(QueueController::class);
		$method = new \ReflectionMethod($controller, $endpoint);
		$args = [];
		foreach ($method->getParameters() as $parameter) {
			$type = $parameter->getType();
			$args[] = ($type instanceof \ReflectionNamedType && !$type->isBuiltin())
				? ($services[$type->getName()] ?? Server::get($type->getName()))
				: 64;
		}
		$response = $method->invokeArgs($controller, $args);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		return $response->getData();
	}

	private function filesInQueue(): int {
		return Server::get(QueueMapper::class)->count();
	}

	public function testFilesWipeHoldsBackOnlyTheFiles(): void {
		$this->pauses[] = $this->fetchGuard->pause(onlyFiles: true);

		$response = $this->call('getDocumentsQueueItems');

		$this->assertCount(1, (array)$response['content_providers']);
		$this->assertSame(1, $this->filesInQueue(), 'the file must not be touched');
		$this->assertCount(1, (array)$this->call('getActionsQueueItems')['actions']);
	}

	public function testFullWipeHoldsBackEverything(): void {
		$this->pauses[] = $this->fetchGuard->pause();

		$response = $this->call('getDocumentsQueueItems');

		$this->assertSame([], (array)$response['content_providers']);
		$this->assertSame(1, $this->filesInQueue());
		$this->assertSame([], (array)$this->call('getActionsQueueItems')['actions']);
	}

	public function testNothingIsHeldBackWithoutAWipe(): void {
		$response = $this->call('getDocumentsQueueItems');

		$this->assertCount(1, (array)$response['content_providers']);
		$this->assertSame(0, $this->filesInQueue(), 'the file was handed out and dropped');
	}

	/**
	 * A full wipe that starts while a fetch is locking its batch counts the handed out documents before the fetch
	 * returns. The fetch must notice the pause after locking and put the items back, or they would be indexed
	 * after the wipe without the wipe having waited for them.
	 */
	public function testItemsLockedAsAFullWipeStartsArePutBack(): void {
		$real = Server::get(QueueContentItemMapper::class);
		$contentQueue = $this->createMock(QueueContentItemMapper::class);
		$contentQueue->method('getFromQueue')->willReturnCallback(fn (int $limit) => $real->getFromQueue($limit));
		$contentQueue->method('lock')->willReturnCallback(function (int $id) use ($real): bool {
			$locked = $real->lock($id);
			$this->pauses[] = $this->fetchGuard->pause();
			return $locked;
		});
		$contentQueue->method('unlock')->willReturnCallback(fn (array $ids) => $real->unlock($ids));

		$response = $this->call('getDocumentsQueueItems', [QueueContentItemMapper::class => $contentQueue]);

		$this->assertSame([], (array)$response['content_providers']);
		$this->assertSame([], $real->countLocked(), 'the item must be back in the queue, the wipe would wait for it');
	}

	public function testActionsLockedAsAFullWipeStartsArePutBack(): void {
		$real = Server::get(QueueActionMapper::class);
		$actionQueue = $this->createMock(QueueActionMapper::class);
		$actionQueue->method('getFromQueue')->willReturnCallback(fn (int $limit) => $real->getFromQueue($limit));
		$actionQueue->method('lock')->willReturnCallback(function (int $id) use ($real): bool {
			$locked = $real->lock($id);
			$this->pauses[] = $this->fetchGuard->pause();
			return $locked;
		});
		$actionQueue->method('unlock')->willReturnCallback(fn (array $ids) => $real->unlock($ids));

		$response = $this->call('getActionsQueueItems', [QueueActionMapper::class => $actionQueue]);

		$this->assertSame([], (array)$response['actions']);
		$this->assertSame(0, $real->countLocked(), 'the action must be back in the queue');
	}

	public function testFilesLockedAsAFilesWipeStartsArePutBack(): void {
		$owner = 'cc_pause_' . substr(md5(uniqid('', true)), 0, 8);
		$user = Server::get(IUserManager::class)->createUser($owner, 'Pass-' . $owner . '-1234567890');
		try {
			$userFolder = Server::get(IRootFolder::class)->getUserFolder($owner);
			$file = $userFolder->newFile('doc.txt', 'content');
			$this->clearQueues();
			$queued = new QueueFile();
			$queued->setFileId($file->getId());
			$queued->setStorageId($userFolder->getStorage()->getCache()->getNumericStorageId());
			$queued->setRootId($userFolder->getId());
			$queued->setUpdate(false);
			$real = Server::get(QueueMapper::class);
			$real->insertIntoQueue($queued);

			$filesQueue = $this->createMock(QueueMapper::class);
			$filesQueue->method('getFromQueue')->willReturnCallback(fn (int $limit) => $real->getFromQueue($limit));
			$filesQueue->method('lock')->willReturnCallback(function (int $id) use ($real): bool {
				$locked = $real->lock($id);
				$this->pauses[] = $this->fetchGuard->pause(onlyFiles: true);
				return $locked;
			});
			$filesQueue->method('unlock')->willReturnCallback(fn (array $ids) => $real->unlock($ids));

			$response = $this->call('getDocumentsQueueItems', [QueueMapper::class => $filesQueue]);

			$this->assertSame([], (array)$response['files']);
			$this->assertSame(1, $real->count(), 'the file must be back in the queue');
			$this->assertSame(0, $real->countLocked());
		} finally {
			$user?->delete();
		}
	}
}
