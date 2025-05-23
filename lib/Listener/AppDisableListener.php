<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Listener;

use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\ActionScheduler;
use OCA\ContextChat\Service\ProviderConfigService;
use OCP\App\Events\AppDisableEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * @template-implements IEventListener<Event>
 */
class AppDisableListener implements IEventListener {
	public function __construct(
		private ProviderConfigService $providerConfig,
		private ActionScheduler $actionService,
		private Logger $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!($event instanceof AppDisableEvent)) {
			return;
		}

		foreach ($this->providerConfig->getProviders() as $key => $values) {
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

			$this->providerConfig->removeProvider($appId, $providerId);
			$this->actionService->deleteProvider($providerId);
		}
	}
}
