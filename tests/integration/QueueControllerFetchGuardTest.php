<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Tests;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Controller\QueueController;
use OCA\ContextChat\Db\QueueAction;
use OCA\ContextChat\Db\QueueActionMapper;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Db\QueueFile;
use OCA\ContextChat\Db\QueueMapper;
use OCA\ContextChat\Service\FetchGuard;
use OCA\ContextChat\Type\ActionType;
use OCP\AppFramework\Http;
use OCP\Files\IRootFolder;
use OCP\Server;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * While the vector DB is wiped the backend must not get anything to index or to apply,
 * and nothing must be taken off the queues for it.
 */
#[Group('DB')]
class QueueControllerFetchGuardTest extends TestCase {
	private QueueController $controller;
	private QueueMapper $queueMapper;
	private QueueActionMapper $actionMapper;
	private FetchGuard $guard;
	private ?int $pausedUntil = null;

	protected function setUp(): void {
		parent::setUp();
		$this->controller = Server::get(QueueController::class);
		$this->queueMapper = Server::get(QueueMapper::class);
		$this->actionMapper = Server::get(QueueActionMapper::class);
		$this->guard = (new Application())->getContainer()->get(FetchGuard::class);
		$this->queueMapper->clearQueue();
		$this->actionMapper->removeFromQueue($this->allActionIds());

		$file = new QueueFile();
		$file->setFileId(42);
		$file->setStorageId(1);
		$file->setRootId(1);
		$file->setUpdate(false);
		$this->queueMapper->insertIntoQueue($file);

		$action = new QueueAction();
		$action->setType(ActionType::DELETE_SOURCE_IDS);
		$action->setPayload('{"sourceIds":["files__default: 42"]}');
		$this->actionMapper->insertIntoQueue($action);
	}

	protected function tearDown(): void {
		if ($this->pausedUntil !== null) {
			$this->guard->resume($this->pausedUntil);
		}
		$this->queueMapper->clearQueue();
		$this->actionMapper->removeFromQueue($this->allActionIds());
		parent::tearDown();
	}

	/** @return list<int> */
	private function allActionIds(): array {
		return array_map(static fn (QueueAction $a) => $a->getId(), $this->actionMapper->getFromQueue(1000));
	}

	public function testNothingIsHandedOutOrLockedWhilePaused(): void {
		$this->pausedUntil = $this->guard->pause();

		$documents = $this->controller->getDocumentsQueueItems(
			Server::get(IRootFolder::class),
			$this->queueMapper,
			Server::get(QueueContentItemMapper::class),
			Server::get(\OCP\Files\Config\IUserMountCache::class),
		);
		$actions = $this->controller->getActionsQueueItems($this->actionMapper);

		$this->assertSame(Http::STATUS_OK, $documents->getStatus());
		$this->assertEquals(['files' => new \stdClass(), 'content_providers' => new \stdClass()], $documents->getData());
		$this->assertEquals(['actions' => new \stdClass()], $actions->getData());
		$this->assertSame(0, $this->queueMapper->countLocked(), 'a paused fetch must not lock queue items');
		$this->assertSame(1, $this->queueMapper->count());
		$this->assertSame(0, $this->actionMapper->countLocked());
		$this->assertSame(1, $this->actionMapper->count());
	}

	public function testActionsAreHandedOutAgainAfterResume(): void {
		$this->pausedUntil = $this->guard->pause();
		$this->guard->resume($this->pausedUntil);
		$this->pausedUntil = null;

		$actions = $this->controller->getActionsQueueItems($this->actionMapper);

		$this->assertCount(1, (array)$actions->getData()['actions']);
	}
}
