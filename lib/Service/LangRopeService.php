<?php
/**
 * Nextcloud - Cwyd
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2023
 */

namespace OCA\Cwyd\Service;

use Exception;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use OCA\Cwyd\AppInfo\Application;
use OCP\Files\File;
use OCP\Http\Client\IClient;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCP\Http\Client\IClientService;
use Throwable;

class LangRopeService {
	private IClient $client;

	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		IClientService $clientService
	) {
		$this->client = $clientService->newClient();
	}

	public function indexFile(string $userId, File $file): void {
		$params = [
			[
				'name' => 'user_id',
				'content' => $userId,
			],
			[
				'name' => $file->getPath(),
				'content' => $file->getContent(),
			],
		];
		$this->request('index', $params, 'POST', 'multipart/form-data');
	}

	public function indexString(string $userId, string $content, string $reference): void {
		$params = [
			[
				'name' => 'user_id',
				'content' => $userId,
			],
			[
				'name' => $reference,
				'content' => $content,
			],
		];
		$this->request('index', $params, 'POST', 'multipart/form-data');
	}

	public function query(string $userId, string $prompt): array {
		$params = [
			'prompt' => $prompt,
			'user_id' => $userId,
		];
		return $this->request('query', $params, 'GET');
	}

	/**
	 * Make an HTTP request to the LangRope API
	 * @param string $endPoint The path to reach
	 * @param array $params Query parameters (key/val pairs)
	 * @param string $method HTTP query method
	 * @param string|null $contentType
	 * @return array decoded request result or error
	 */
	public function request(string $endPoint, array $params = [], string $method = 'GET', ?string $contentType = null): array {
		try {
			$serviceUrl = $this->config->getAppValue(Application::APP_ID, 'url', Application::LANGROPE_BASE_URL) ?: Application::LANGROPE_BASE_URL;
			$timeout = $this->config->getAppValue(Application::APP_ID, 'request_timeout', Application::CWYD_DEFAULT_REQUEST_TIMEOUT) ?: Application::CWYD_DEFAULT_REQUEST_TIMEOUT;
			$timeout = (int) $timeout;

			$url = $serviceUrl . '/' . $endPoint;
			$options = [
				'timeout' => $timeout,
				'headers' => [
					'User-Agent' => 'Nextcloud CWYD app',
				],
			];

			if ($contentType === null) {
				$options['headers']['Content-Type'] = 'application/json';
			} elseif ($contentType === 'multipart/form-data') {
				// no header in this case
				// $options['headers']['Content-Type'] = $contentType;
			} else {
				$options['headers']['Content-Type'] = $contentType;
			}

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					if ($contentType === 'multipart/form-data') {
						$options['multipart'] = $params;
					} else {
						$options['body'] = json_encode($params);
					}
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return json_decode($body, true) ?: [];
			}
		} catch (ClientException | ServerException $e) {
			$responseBody = $e->getResponse()->getBody();
			$parsedResponseBody = json_decode($responseBody, true);
			if ($e->getResponse()->getStatusCode() === 404) {
				$this->logger->debug('Cwyd API error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
			} else {
				$this->logger->warning('Cwyd API error : ' . $e->getMessage(), ['response_body' => $responseBody, 'exception' => $e]);
			}
			return [
				'error' => $e->getMessage(),
				'body' => $parsedResponseBody,
			];
		} catch (Exception | Throwable $e) {
			$this->logger->warning('Cwyd API error : ' . $e->getMessage(), ['exception' => $e]);
			return ['error' => $e->getMessage()];
		}
	}
}