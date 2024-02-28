<?php
/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @author Anupam Kumar
 * @author AppAPI Developers
 * @copyright Julien Veyssier 2023
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Public\IContentProvider;
use OCA\ContextChat\Type\Source;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Server;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

enum ScopeType: string {
	case PROVIDER = 'provider';
	case SOURCE = 'source';
}

class LangRopeService {
	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
		private IUserManager $userMan,
		private ProviderConfigService $configService,
		private ?string $userId,
	) {
	}

	private function requestToExApp(
		string $route,
		string $method = 'POST',
		array $params = [],
		?string $contentType = null,
	): array {
		$user = $this->userId === null ? null : $this->userMan->get($this->userId);
		if (!$this->appManager->isEnabledForUser('app_api', $user)) {
			$this->logger->error('AppAPI is not enabled, please enable it or install the AppAPI app from the Nextcloud AppStore');
			throw new RuntimeException($this->l10n->t('AppAPI is not enabled, please enable it or install the AppAPI app from the Nextcloud AppStore'));
		}

		if (version_compare($this->appManager->getAppVersion('app_api', false), Application::MIN_APP_API_VERSION, '<')) {
			$this->logger->error('AppAPI app version is too old, please update it to at least ' . Application::MIN_APP_API_VERSION);
			throw new RuntimeException($this->l10n->t('AppAPI app version is too old, please update it to at least %s', Application::MIN_APP_API_VERSION));
		}

		try {
			$appApiFunctions = \OCP\Server::get(\OCA\AppAPI\PublicFunctions::class);
		} catch (ContainerExceptionInterface | NotFoundExceptionInterface $e) {
			$this->logger->error('Could not get AppAPI public functions', ['exception' => $e]);
			throw new RuntimeException($this->l10n->t('Could not get AppAPI public functions'));
		}

		$timeout = $this->config->getAppValue(
			Application::APP_ID,
			'request_timeout',
			strval(Application::CC_DEFAULT_REQUEST_TIMEOUT),
		) ?: Application::CC_DEFAULT_REQUEST_TIMEOUT;

		$options = [
			'timeout' => $timeout,
		];

		if ($contentType === null) {
			$options['headers']['Content-Type'] = 'application/json';
		} elseif ($contentType === 'multipart/form-data') {
			// no header in this case, $options['multipart'] sets the Content-Type
		} else {
			$options['headers']['Content-Type'] = $contentType;
		}

		if (count($params) > 0 && $method !== 'GET') {
			if ($contentType === 'multipart/form-data') {
				$options['multipart'] = $params;
			} else {
				$options['body'] = json_encode($params);
			}
		}

		$response = $appApiFunctions->exAppRequest(
			'context_chat_backend',
			$route,
			$this->userId,
			$method,
			$params,
			$options,
		);
		if (is_array($response) && isset($response['error'])) {
			$this->logger->error('Error during request to ExApp (context_chat_backend): ' . $response['error']);
			throw new RuntimeException($this->l10n->t('Error during request to ExApp (context_chat_backend): ') . $response['error']);
		}

		$resContentType = $response->getHeader('Content-Type');
		if (strpos($resContentType, 'application/json') !== false) {
			$body = $response->getBody();
			if (!is_string($body)) {
				$this->logger->error('Error during request to ExApp (context_chat_backend): response body is not a string, but content type is application/json', ['response' => $response]);
				throw new RuntimeException($this->l10n->t('Error during request to ExApp (context_chat_backend): response body is not a string, but content type is application/json'));
			}

			return json_decode($body, true);
		}

		return ['response' => $response->getBody()];
	}

	/**
	 * @param string $userId
	 * @param string $providerKey
	 * @return void
	 */
	public function deleteSourcesByProvider(string $userId, string $providerKey): void {
		$params = [
			'userId' => $userId,
			'providerKey' => $providerKey,
		];

		$this->requestToExApp('/deleteSourcesByProvider', 'POST', $params);
	}

	/**
	 * @param string $userId
	 * @param string[] $sourceNames
	 * @return void
	 */
	public function deleteSources(string $userId, array $sourceNames): void {
		if (count($sourceNames) === 0) {
			return;
		}

		$params = [
			'userId' => $userId,
			'sourceNames' => $sourceNames,
		];

		$this->requestToExApp('/deleteSources', 'POST', $params);
	}

	/**
	 * @param Source[] $sources
	 * @return void
	 */
	public function indexSources(array $sources): void {
		if (count($sources) === 0) {
			return;
		}

		$params = array_map(function (Source $source) {
			return [
				'name' => 'sources',
				'filename' => $source->reference, // eg. 'file: 555'
				'contents' => $source->content,
				'headers' => [
					'userId' => $source->userId,
					'title' => $source->title,
					'type' => $source->type,
					'modified' => $source->modified,
					'provider' => $source->provider, // eg. 'file'
				],
			];
		}, $sources);

		$this->requestToExApp('/loadSources', 'PUT', $params, 'multipart/form-data');
	}

	public function query(string $userId, string $prompt, bool $useContext = true): array {
		$params = [
			'query' => $prompt,
			'userId' => $userId,
			'useContext' => $useContext,
		];

		$response = $this->requestToExApp('/query', 'GET', $params);
		return ['message' => $this->getWithPresentableSources($response['output'] ?? '', ...($response['sources'] ?? []))];
	}

	/**
	 * @param string $userId
	 * @param string $prompt
	 * @param ScopeType $scopeType
	 * @param array<string> $scopeList
	 * @return array
	 */
	public function scopedQuery(string $userId, string $prompt, ScopeType $scopeType, array $scopeList): array {
		$params = [
			'query' => $prompt,
			'userId' => $userId,
			'scopeType' => $scopeType,
			'scopeList' => $scopeList,
		];

		$response = $this->requestToExApp('/scopedQuery', 'POST', $params);
		return ['message' => $this->getWithPresentableSources($response['output'] ?? '', ...($response['sources'] ?? []))];
	}

	public function getWithPresentableSources(string $llmResponse, string ...$sourceRefs): string {
		if (count($sourceRefs) === 0) {
			return $llmResponse;
		}

		$output = str_repeat(PHP_EOL, 3) . $this->l10n->t('Sources referenced to generate the above response:') . PHP_EOL;

		foreach ($sourceRefs as $source) {
			if (str_starts_with($source, 'file: ') && is_numeric($fileId = substr($source, 6))) {
				// use `overwritehost` setting in config.php to overwrite the host
				$output .= $this->urlGenerator->linkToRouteAbsolute('files.View.showFile', ['fileid' => $fileId]) . PHP_EOL;
			} elseif (str_contains($source, '__')) {
				// source id ($appId__$providerId: $itemId)
				/** @var string[] */
				$sourceValues = explode('__', $source, 2);

				if (empty($sourceValues)) {
					$this->logger->warning('Invalid source id', ['source' => $source]);
					continue;
				}

				[$identifier, $itemId] = $sourceValues;

				try {
					/** @var IContentProvider */
					$providerObj = Server::get($identifier);
				} catch (ContainerExceptionInterface | NotFoundExceptionInterface $e) {
					$this->logger->warning('Could not run initial import for content provider', ['exception' => $e]);
					continue;
				}

				$output .= $providerObj->getItemUrl($itemId) . PHP_EOL;
			}
		}

		return $llmResponse . $output;
	}
}
