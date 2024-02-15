<?php
/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Anupam Kumar <kyteinsky@gmail.com>
 * @copyright Anupam Kumar 2024
 */

declare(strict_types=1);

namespace OCA\ContextChat\Listener;

use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCP\App\Events\AppDisableEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class AppDisableListener implements IEventListener {
	public function __construct(
		private ProviderConfigService $configService,
		private LangRopeService $service,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof AppDisableEvent)) {
			return;
		}

		if ($this->userId === null) {
			$this->logger->warning('No user id provided for app disable');
			return;
		}

		foreach ($this->configService->getProviders() as $key => $values) {
			/** @var string[] */
			$identifierValues = explode('__', $key, 2);

			if (empty($identifierValues)) {
				$this->logger->warning('Invalid provider key', ['key' => $key]);
				continue;
			}

			[$appId, $providerId] = $identifierValues;

			if ($appId !== $event->getAppId()) {
				continue;
			}

			$this->configService->removeProvider($appId, $providerId);
			$this->service->deleteMatchingSources($this->userId, $key);
		}
	}
}
