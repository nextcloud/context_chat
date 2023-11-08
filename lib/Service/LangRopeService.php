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

use DateTime;
use OCA\AppAPI\Db\ExApp;
use OCA\AppAPI\Service\AppAPIService;
use OCA\Cwyd\AppInfo\Application;
use OCA\Cwyd\Type\Source;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class LangRopeService {
	private IClient $client;

	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		private IAppManager $appManager,
		private AppAPIService $appAPIService,
		IClientService $clientService,
	) {
		$this->client = $clientService->newClient();
	}

	public function indexSources(string $userId, array $sources): void {
		if (count($sources) === 0) {
			return;
		}

		$params = [
			...array_map(function (Source $source) {
				return [
					'name' => 'sources',
					'filename' => $source->reference, // 'file: 555'
					'contents' => $source->content,
					'headers' => [
						'userId' => $source->userId,
						'type' => $source->type,
						'modified' => $source->modified,
					],
				];
			}, $sources),
		];

		$exApp = $this->appAPIService->getExApp('schackles');
		$this->requestToExApp($userId, $exApp, '/loadSources', 'PUT', $params, 'multipart/form-data');
	}

	public function query(string $userId, string $prompt): array {
		$params = [
			'query' => $prompt,
			'userId' => $userId,
		];

		$exApp = $this->appAPIService->getExApp('schackles');
		return $this->requestToExApp($userId, $exApp, '/query', 'GET', $params);
	}

	private static function getExAppUrl(string $protocol, string $host, int $port): string {
		return sprintf('%s://%s:%s', $protocol, $host, $port);
	}

	/**
	 * Request to ExApp with AppAPI auth headers
	 *
	 * @param string|null $userId
	 * @param ExApp $exApp
	 * @param string $route
	 * @param string $method
	 * @param array $params
	 *
	 * @return array|IResponse|null
	 */
	public function requestToExApp(
		?string $userId,
		ExApp $exApp,
		string $route,
		string $method = 'POST',
		array $params = [],
		?string $contentType = null,
	): array | IResponse | null {
		if (!class_exists('\OCA\AppAPI\Db\ExApp')) {
			$this->logger->error('ExApp class not found, please install the AppAPI app from the Nextcloud AppStore');
			return ['error' => 'ExApp class not found, please install the AppAPI app from the Nextcloud AppStore'];
		}

		try {
			$url = self::getExAppUrl(
				$exApp->getProtocol(),
				$exApp->getHost(),
				$exApp->getPort()
			) . $route;

			$timeout = $this->config->getAppValue(Application::APP_ID, 'request_timeout', Application::CWYD_DEFAULT_REQUEST_TIMEOUT) ?: Application::CWYD_DEFAULT_REQUEST_TIMEOUT;

			$options = [
				'headers' => [
					'AA-VERSION' => $this->appManager->getAppVersion(Application::APP_ID, false),
					'EX-APP-ID' => $exApp->getAppid(),
					'EX-APP-VERSION' => $exApp->getVersion(),
					'AUTHORIZATION-APP-API' => base64_encode($userId . ':' . $exApp->getSecret()),
				],
				'nextcloud' => [
					'allow_local_address' => true, // it's required as we are using ExApp appid as hostname (usually local)
				],
				'timeout' => $timeout,
			];

			if ($contentType === null) {
				$options['headers']['Content-Type'] = 'application/json';
			} elseif ($contentType === 'multipart/form-data') {
				// no header in this case, $options['multipart'] sets the Content-Type
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

			switch ($method) {
				case 'GET':
					$response = $this->client->get($url, $options);
					break;
				case 'POST':
					$response = $this->client->post($url, $options);
					break;
				case 'PUT':
					$response = $this->client->put($url, $options);
					break;
				case 'DELETE':
					$response = $this->client->delete($url, $options);
					break;
				default:
					return ['error' => $this->l10n->t('Bad HTTP method')];
			}

			$resContentType = $response->getHeader('Content-Type');
			$statusCode = $response->getStatusCode();
			$body = $response->getBody();

			if (strpos($resContentType, 'application/json') !== false) {
				$body = json_decode($body, true);
			}

			if ($statusCode >= 400 || isset($body['error'])) {
				$this->logger->error(
					sprintf('Error during request to ExApp %s: %s', $exApp->getAppid(), $body['error']),
					['response' => $response]
				);
				return [
					'error' => $this->l10n->t('Error during request to ExApp') . $exApp->getAppid() . ': ' . $body['error']
				];
			}

			return $body;
		} catch (\Exception $e) {
			$this->logger->error(
				sprintf('Error during request to ExApp %s: %s', $exApp->getAppid(), $e->getMessage()),
				['exception' => $e]
			);
			return [
				'error' => $this->l10n->t('Error during request to ExApp') . $exApp->getAppid() . ': ' . $e->getMessage()
			];
		}
	}
}
