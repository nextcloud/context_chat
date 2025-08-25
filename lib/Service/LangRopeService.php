<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Exceptions\RetryIndexException;
use OCA\ContextChat\Logger;
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
use RuntimeException;

class LangRopeService {
	public function __construct(
		private Logger $logger,
		private IL10N $l10n,
		private IConfig $config,
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
		private IUserManager $userMan,
		private ProviderConfigService $providerService,
		private ?string $userId,
	) {
	}

	/**
	 * @param string $route
	 * @param string $method
	 * @param array $params
	 * @param string|null $contentType
	 * @return array
	 * @throws RuntimeException
	 */
	private function requestToExApp(
		string $route,
		string $method = 'POST',
		array $params = [],
		?string $contentType = null,
	): array {
		$user = $this->userId === null ? null : $this->userMan->get($this->userId);
		if (!$this->appManager->isEnabledForUser('app_api', $user)) {
			throw new RuntimeException('AppAPI is not enabled, please enable it or install the AppAPI app from the Nextcloud AppStore');
		}

		if (version_compare($this->appManager->getAppVersion('app_api', false), Application::MIN_APP_API_VERSION, '<')) {
			throw new RuntimeException('AppAPI app version is too old, please update it to at least ' . Application::MIN_APP_API_VERSION);
		}

		// todo: app_api is always available now (composer update)
		try {
			$appApiFunctions = \OCP\Server::get(\OCA\AppAPI\PublicFunctions::class);
		} catch (ContainerExceptionInterface|NotFoundExceptionInterface $e) {
			throw new RuntimeException('Could not get AppAPI public functions');
		}

		// backend init check
		$backendInit = $this->config->getAppValue(Application::APP_ID, 'backend_init', 'false');
		if ($backendInit !== 'true') {
			$enabledResponse = $appApiFunctions->exAppRequest('context_chat_backend', '/enabled', $this->userId, 'GET');

			if (is_array($enabledResponse) && isset($enabledResponse['error'])) {
				throw new RuntimeException('Error during request to Context Chat Backend (ExApp): ' . $enabledResponse['error']);
			}

			$enabledResponse = $enabledResponse->getBody();

			if (!is_string($enabledResponse)) {
				$this->logger->error('Error during request to Context Chat Backend (ExApp): response body is not a string', ['response' => $enabledResponse]);
				throw new RuntimeException('Error during request to Context Chat Backend (ExApp): response body is not a string');
			}

			$enabledResponse = json_decode($enabledResponse, true);
			if ($enabledResponse === null) {
				$this->logger->error('Error during request to Context Chat Backend (ExApp): response body is not a valid JSON', ['response' => $enabledResponse]);
				throw new RuntimeException('Error during request to Context Chat Backend (ExApp): response body is not a valid JSON');
			}

			if (isset($enabledResponse['enabled']) && $enabledResponse['enabled'] === true) {
				$this->config->setAppValue(Application::APP_ID, 'backend_init', 'true');
			} else {
				$this->config->setAppValue(Application::APP_ID, 'backend_init', 'false');
				throw new RuntimeException('Context Chat backend is not ready yet. Please wait a while before trying again.');
			}
		}

		$timeout = $this->config->getAppValue(
			Application::APP_ID,
			'request_timeout',
			strval(Application::CC_DEFAULT_REQUEST_TIMEOUT),
		);
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
			throw new RuntimeException('Error during request to Context Chat Backend (ExApp): ' . $response['error']);
		}
		if (is_array($response)) {
			// this should never happen since app_api only returns errors in an array
			throw new RuntimeException('Error during request to Context Chat Backend (ExApp): response is not a valid response object');
		}

		$resContentType = $response->getHeader('Content-Type');
		if (strpos($resContentType, 'application/json') !== false) {
			$body = $response->getBody();
			if (!is_string($body)) {
				$this->logger->error('Error during request to Context Chat Backend (ExApp): response body is not a string, but content type is application/json', ['response' => $response]);
				throw new RuntimeException('Error during request to Context Chat Backend (ExApp): response body is not a string, but content type is application/json');
			}

			$finalBody = json_decode($body, true);
		} else {
			$finalBody = ['response' => $response->getBody()];
		}

		if (intval($response->getStatusCode() / 100) !== 2) {
			$this->logger->error('Error received from Context Chat Backend (ExApp)', [
				'code' => $response->getStatusCode(),
				'response' => $finalBody,
				'route' => $route,
				'method' => $method,
				'retry' => $response->getHeader('cc-retry'),
			]);

			if ($response->getHeader('cc-retry') === 'true') {
				// At least one source is already being processed from another request, retry in some time
				throw new RetryIndexException('At least one source is already being processed from another request, retry in some time');
			}

			if ($response->getStatusCode() >= 500) {
				// only throw for 5xx errors
				throw new RuntimeException(
					'Error received from Context Chat Backend (ExApp) with status code '
					. $response->getStatusCode()
					. ': '
					. (isset($finalBody['error']) ? $finalBody['error'] : 'unknown error')
				);
			}
		}

		return $finalBody;
	}

	/**
	 * @return array<array-key, int>
	 * @throws \RuntimeException
	 */
	public function getIndexedDocumentsCounts(): array {
		$response = $this->requestToExApp('/countIndexedDocuments', 'POST');
		if ($response === []) {
			// No documents indexed yet
			return [];
		}
		if (!isset($response[ProviderConfigService::getDefaultProviderKey()])) {
			throw new \RuntimeException("Malformed indexed documents count response from Context Chat Backend (ExApp): '"
				. ProviderConfigService::getDefaultProviderKey() . "' key is missing, response: " . strval(json_encode($response)));
		}
		return $response;
	}

	/**
	 * @param string[] $sourceIds
	 * @return void
	 * @throws \RuntimeException
	 */
	public function deleteSources(array $sourceIds): void {
		$params = [
			'sourceIds' => $sourceIds,
		];
		$this->requestToExApp('/deleteSources', 'POST', $params);
	}

	/**
	 * @param string $providerKey
	 * @return void
	 * @throws \RuntimeException
	 */
	public function deleteProvider(string $providerKey): void {
		$params = [
			'providerKey' => $providerKey,
		];
		$this->requestToExApp('/deleteProvider', 'POST', $params);
	}

	/**
	 * @param string $userId
	 * @return void
	 * @throws \RuntimeException
	 */
	public function deleteUser(string $userId): void {
		$params = [
			'userId' => $userId,
		];
		$this->requestToExApp('/deleteUser', 'POST', $params);
	}

	/**
	 * @param string $op UpdateAccessOp::*
	 * @param string[] $userIds
	 * @param string $sourceId
	 * @return void
	 * @throws \RuntimeException
	 */
	public function updateAccess(string $op, array $userIds, string $sourceId): void {
		$params = [
			'op' => $op,
			'userIds' => $userIds,
			'sourceId' => $sourceId,
		];
		$this->requestToExApp('/updateAccess', 'POST', $params);
	}

	/**
	 * @param string $op UpdateAccessOp::*
	 * @param string[] $userIds
	 * @param string $providerId
	 * @return void
	 * @throws \RuntimeException
	 */
	public function updateAccessProvider(string $op, array $userIds, string $providerId): void {
		$params = [
			'op' => $op,
			'userIds' => $userIds,
			'providerId' => $providerId,
		];
		$this->requestToExApp('/updateAccessProvider', 'POST', $params);
	}

	/**
	 * @param string[] $userIds
	 * @param string $sourceId
	 * @return void
	 * @throws \RuntimeException
	 */
	public function updateAccessDeclarative(array $userIds, string $sourceId): void {
		$params = [
			'userIds' => $userIds,
			'sourceId' => $sourceId,
		];
		$this->requestToExApp('/updateAccessDeclarative', 'POST', $params);
	}

	/**
	 * @param Source[] $sources
	 * @return array{loaded_sources: array<string>, sources_to_retry: array<string>}
	 * @throws RuntimeException|RetryIndexException
	 */
	public function indexSources(array $sources): array {
		if (count($sources) === 0) {
			return ['loaded_sources' => [], 'sources_to_retry' => []];
		}

		$params = array_map(function (Source $source) {
			return [
				'name' => 'sources',
				'filename' => $source->reference, // eg. 'files__default: 555'
				'contents' => $source->content,
				'headers' => [
					'userIds' => implode(',', $source->userIds),
					'title' => $source->title,
					'type' => $source->type,
					'modified' => $source->modified,
					'provider' => $source->provider, // eg. 'files__default'
				],
			];
		}, $sources);

		$response = $this->requestToExApp('/loadSources', 'PUT', $params, 'multipart/form-data');
		if (
			!isset($response['loaded_sources']) || !is_array($response['loaded_sources'])
			|| !isset($response['sources_to_retry']) || !is_array($response['sources_to_retry'])
		) {
			throw new RuntimeException('Error during request to Context Chat Backend (ExApp): Expected keys "loaded_sources" and "sources_to_retry" in response. Please upgrade the Context Chat Backend app to the latest version.');
		}
		return ['loaded_sources' => $response['loaded_sources'], 'sources_to_retry' => $response['sources_to_retry']];
	}

	/**
	 * @param string $userId
	 * @param string $prompt
	 * @param bool $useContext
	 * @param ?string $scopeType
	 * @param ?array<string> $scopeList
	 * @return array
	 * @throws RuntimeException
	 */
	public function query(string $userId, string $prompt, bool $useContext = true, ?string $scopeType = null, ?array $scopeList = null): array {
		$params = [
			'query' => $prompt,
			'userId' => $userId,
			'useContext' => $useContext,
		];
		if ($scopeType !== null && $scopeList !== null) {
			$params['useContext'] = true;
			$params['scopeType'] = $scopeType;
			$params['scopeList'] = $scopeList;
		}

		return $this->requestToExApp('/query', 'POST', $params);
	}

	/**
	 * @param string $userId
	 * @param string $prompt
	 * @param ?string $scopeType
	 * @param ?array<string> $scopeList
	 * @param int|null $limit
	 * @return array
	 */
	public function docSearch(string $userId, string $prompt, ?string $scopeType = null, ?array $scopeList = null, ?int $limit = null): array {
		$params = [
			'query' => $prompt,
			'userId' => $userId,
		];
		if ($scopeType !== null && $scopeList !== null) {
			$params['scopeType'] = $scopeType;
			$params['scopeList'] = $scopeList;
		}
		if ($limit !== null) {
			$params['ctxLimit'] = $limit;
		}

		return $this->requestToExApp('/docSearch', 'POST', $params);
	}

	/**
	 * @param string $userId
	 * @param string $prompt
	 * @param bool $useContext
	 * @param ?string $scopeType
	 * @param ?array<string> $scopeList
	 * @return array
	 * @throws RuntimeException
	 */
	public function textProcessingQuery(string $userId, string $prompt, bool $useContext = true, ?string $scopeType = null, ?array $scopeList = null): array {
		[$output, $sources] = $this->query($userId, $prompt, $useContext, $scopeType, $scopeList);
		return ['message' => $this->getWithPresentableSources($output ?? '', ...($sources ?? []))];
	}

	public function getWithPresentableSources(string $llmResponse, string ...$sourceRefs): string {
		if (count($sourceRefs) === 0) {
			return $llmResponse;
		}

		$output = str_repeat(PHP_EOL, 3) . $this->l10n->t('Sources referenced to generate the above response:') . PHP_EOL;

		$emptyFilesSourceId = ProviderConfigService::getSourceId('');
		foreach ($sourceRefs as $source) {
			if (str_starts_with($source, $emptyFilesSourceId) && is_numeric($fileId = substr($source, strlen($emptyFilesSourceId)))) {
				// use `overwritehost` setting in config.php to overwrite the host
				$output .= $this->urlGenerator->linkToRouteAbsolute('files.View.showFile', ['fileid' => $fileId]) . PHP_EOL;
			} elseif (str_contains($source, '__')) {
				// source id ($appId__$providerId: $itemId)
				/** @var string[] */
				$sourceValues = explode(': ', $source, 2);

				if (empty($sourceValues)) {
					$this->logger->warning('Invalid source id', ['source' => $source]);
					continue;
				}

				[$identifier, $itemId] = $sourceValues;

				$provider = $this->providerService->getProvider($identifier);
				if ($provider === null) {
					$this->logger->warning("No provider found for source id $identifier");
					continue;
				}

				if (!isset($provider['classString'])) {
					$this->logger->warning('Provider does not have a class string', ['provider' => $provider]);
					continue;
				}

				$classString = $provider['classString'];

				try {
					/** @var IContentProvider */
					$providerObj = Server::get($classString);
				} catch (ContainerExceptionInterface|NotFoundExceptionInterface $e) {
					$this->logger->warning('Could not run initial import for content provider', ['exception' => $e]);
					continue;
				}

				$output .= $providerObj->getItemUrl($itemId) . PHP_EOL;
			}
		}

		return $llmResponse . $output;
	}
}
