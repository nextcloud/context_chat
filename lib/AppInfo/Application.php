<?php
/**
 * Nextcloud - Cwyd
 *
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2023
 */

namespace OCA\Cwyd\AppInfo;

use OCA\Cwyd\Listener\CwydReferenceListener;
use OCA\Cwyd\Reference\CwydReferenceProvider;
use OCA\Cwyd\TextProcessing\CwydTextProcessingProvider;
use OCP\Collaboration\Reference\RenderReferenceEvent;
use OCP\IConfig;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;

class Application extends App implements IBootstrap {

	public const APP_ID = 'cwyd';

	public const LANGROPE_BASE_URL = 'http://localhost:8008';
	public const CWYD_DEFAULT_REQUEST_TIMEOUT = 60 * 4;

	private IConfig $config;

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$this->config = $container->query(IConfig::class);
	}

	public function register(IRegistrationContext $context): void {
		// Listen to file change
		// $context->registerEventListener(NodeDeletedEvent::class, CwydFileListener::class);

		$context->registerEventListener(RenderReferenceEvent::class, CwydReferenceListener::class);
		$context->registerTextProcessingProvider(CwydTextProcessingProvider::class);
		$context->registerReferenceProvider(CwydReferenceProvider::class);
	}

	public function boot(IBootContext $context): void {
	}
}

