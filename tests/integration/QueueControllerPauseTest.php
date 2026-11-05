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
use OCP\IDBConnection;
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
	 * @return array<string, mixed>
	 */
	private function call(string $endpoint): array {
		$controller = Server::get(QueueController::class);
		$method = new \ReflectionMethod($controller, $endpoint);
		$args = [];
		foreach ($method->getParameters() as $parameter) {
			$type = $parameter->getType();
			$args[] = ($type instanceof \ReflectionNamedType && !$type->isBuiltin())
				? Server::get($type->getName())
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
}
