<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Tests;

use DateTime;
use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\BackgroundJobs\InitialContentImportJob;
use OCA\ContextChat\BackgroundJobs\SubmitContentJob;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Event\ContentProviderRegisterEvent;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Public\ContentItem;
use OCA\ContextChat\Public\ContentManager;
use OCA\ContextChat\Public\IContentProvider;
use OCA\ContextChat\Service\ActionScheduler;
use OCA\ContextChat\Service\ProviderConfigService;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IServerContainer;
use OCP\Server;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher as SymfonyDispatcher;
use Test\TestCase;

class ContentManagerTest extends TestCase {
	/** @var MockObject | QueueContentItemMapper */
	private QueueContentItemMapper $mapper;
	/** @var MockObject | ProviderConfigService */
	private ProviderConfigService $providerConfig;
	/** @var MockObject | ActionScheduler */
	private ActionScheduler $actionService;

	private ContentManager $contentManager;
	private LoggerInterface $logger;
	private IJobList $jobList;
	private IEventDispatcher $eventDispatcher;
	private SymfonyDispatcher $dispatcher;
	private IServerContainer $serverContainer;

	// private bool $initCalled = false;
	private static string $providerClass = 'OCA\ContextChat\Tests\ContentProvider';

	public function setUp(): void {
		$this->jobList = Server::get(IJobList::class);
		$this->logger = Server::get(LoggerInterface::class);

		$this->mapper = $this->createMock(QueueContentItemMapper::class);
		$this->providerConfig = $this->createMock(ProviderConfigService::class);
		$this->actionService = $this->createMock(ActionScheduler::class);

		// new dispatcher for each test
		$this->dispatcher = new SymfonyDispatcher();
		$this->serverContainer = Server::get(IServerContainer::class);
		$this->eventDispatcher = new \OC\EventDispatcher\EventDispatcher(
			$this->dispatcher,
			$this->serverContainer,
			$this->logger,
		);

		$this->providerConfig
			->method('getProviders')
			->willReturn([
				ProviderConfigService::getDefaultProviderKey() => [
					'isInitiated' => true,
					'classString' => '',
				],
				ProviderConfigService::getConfigKey(Application::APP_ID, 'test-provider') => [
					'isInitiated' => false,
					'classString' => static::$providerClass,
				],
			]);

		// $this->overwriteService(ProviderConfigService::class, $this->providerConfig);

		// using this app's app id to pass the check that the app is enabled for the user
		$providerObj = new ContentProvider(Application::APP_ID, 'test-provider', function () {
			// $this->initCalled = true;
		});
		$providerClass = get_class($providerObj);

		\OC::$server->registerService($providerClass, function () use ($providerObj) {
			return $providerObj;
		});

		$this->contentManager = new ContentManager(
			$this->jobList,
			$this->providerConfig,
			$this->mapper,
			$this->actionService,
			Server::get(Logger::class),
			$this->eventDispatcher,
		);
	}

	public static function dataBank(): array {
		return [
			/* [$classString, $appId, $providerId, $registrationSuccessful] */
			[ static::$providerClass, Application::APP_ID, 'test-provider', true ],
			[ 'invalid', Application::APP_ID, 'test-provider', false ],
		];
	}

	/**
	 * @param class-string<IContentProvider> $providerClass
	 * @param string $appId
	 * @param string $providerId
	 * @param bool $registrationSuccessful
	 * @dataProvider dataBank
	 */
	public function testRegisterContentProvider(
		string $providerClass,
		string $appId,
		string $providerId,
		bool $registrationSuccessful,
	): void {
		$this->providerConfig
			->expects($this->once())
			->method('hasProvider')
			->with($appId, $providerId)
			->willReturn(false);

		$this->providerConfig
			->expects($registrationSuccessful ? $this->once() : $this->never())
			->method('updateProvider')
			->with($appId, $providerId, $providerClass);

		// register the listener for the event
		$this->eventDispatcher->addListener(
			ContentProviderRegisterEvent::class,
			function (ContentProviderRegisterEvent $event) use ($appId, $providerId, $providerClass) {
				if (!($event instanceof ContentProviderRegisterEvent)) {
					return;
				}
				$event->registerContentProvider($appId, $providerId, $providerClass);
			},
		);

		// sample action that should trigger the registration
		$this->contentManager->removeAllContentForUsers($appId, $providerId, ['user1', 'user2']);

		$jobsIter = $this->jobList->getJobsIterator(InitialContentImportJob::class, 1, 0);
		if ($registrationSuccessful) {
			$this->assertNotNull($jobsIter);
			$this->jobList->remove(InitialContentImportJob::class, $providerClass);
		}
	}

	public function testSubmitContent(): void {
		$appId = 'test';
		$items = [
			new ContentItem(
				'item-id',
				'provider-id',
				'title',
				'content',
				'email-file',
				new DateTime(),
				['user1', 'user2'],
			),
		];

		$this->mapper
			->expects($this->once())
			->method('insert');

		$this->jobList->remove(SubmitContentJob::class, null);
		$this->assertFalse($this->jobList->has(SubmitContentJob::class, null));

		$this->contentManager->submitContent($appId, $items);

		$this->assertTrue($this->jobList->has(SubmitContentJob::class, null));
		$this->jobList->remove(SubmitContentJob::class, null);
	}
}

class ContentProvider implements IContentProvider {
	public function __construct(
		private string $appId,
		private string $providerId,
		private $callback,
	) {
	}

	public function getId(): string {
		return $this->providerId;
	}

	public function getAppId(): string {
		return $this->appId;
	}

	public function getItemUrl(string $id): string {
		return 'https://nextcloud.local/test-provider/' . $id;
	}

	public function triggerInitialImport(): void {
		($this->callback)();
	}
}
