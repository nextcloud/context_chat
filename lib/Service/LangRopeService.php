<?php

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Exceptions\FatalRequestException;
use OCA\ContextChat\Exceptions\RetryIndexException;
use OCA\ContextChat\Logger;
use OCP\App\IAppManager;
use OCP\AppFramework\Services\IAppConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;

class LangRopeService {
	public function __construct(
		private Logger $logger,
		private IL10N $l10n,
		private IAppConfig $appConfig,
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
	 * @throws FatalRequestException
	 */
	private function requestToExApp(
		string $route,
		string $method = 'POST',
		array $params = [],
		?string $contentType = null,
		array $extraOptions = [],
	): array {
		$user = $this->userId === null ? null : $this->userMan->get($this->userId);
		if (!$this->appManager->isEnabledForUser('app_api', $user)) {
			throw new RuntimeException('AppAPI is not enabled, please enable it or install the AppAPI app from the Nextcloud AppStore');
		}

		// app_api may not be always enabled
		try {
			$appApiFunctions = \OCP\Server::get(\OCA\AppAPI\PublicFunctions::class);
		} catch (ContainerExceptionInterface|NotFoundExceptionInterface $e) {
			throw new RuntimeException('Could not get AppAPI public functions');
		}

		// backend init check
		$backendInit = $this->appConfig->getAppValueString('backend_init', 'false', lazy: true);
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
				$this->appConfig->setAppValueString('backend_init', 'true', lazy: true);
			} else {
				$this->appConfig->setAppValueString('backend_init', 'false', lazy: true);
				throw new RuntimeException('Context Chat backend is not ready yet. Please wait a while before trying again.');
			}
		}

		$timeout = $this->appConfig->getAppValueString(
			'request_timeout',
			strval(Application::CC_DEFAULT_REQUEST_TIMEOUT),
			lazy: true,
		);
		$options = [
			'timeout' => $timeout,
			// assumed it is not be a nested array
			...$extraOptions,
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
				throw new RuntimeException(
					'Error received from Context Chat Backend (ExApp) with status code '
					. $response->getStatusCode()
					. ': '
					. (isset($finalBody['error']) ? $finalBody['error'] : 'unknown error')
				);
			}

			if ($response->getStatusCode() >= 400) {
				throw new FatalRequestException(
					'Error received from Context Chat Backend (ExApp) with status code '
					. $response->getStatusCode()
					. ': '
					. (isset($finalBody['error']) ? $finalBody['error'] : json_encode($finalBody))
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
		$response = $this->requestToExApp('/countIndexedDocuments', 'POST', extraOptions: ['timeout' => 10]);
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
}
