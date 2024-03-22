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
		private ProviderConfigService $providerConfig,
		private LangRopeService $service,
		private LoggerInterface $logger,
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
			$this->service->deleteSourcesByProviderForAllUsers($providerId);
		}
	}
}
