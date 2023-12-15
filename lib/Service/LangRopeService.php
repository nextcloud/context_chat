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
use OCA\ContextChat\Type\Source;
use OCP\App\IAppManager;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class LangRopeService {
	private IClient $client;

	public function __construct(
		private LoggerInterface $logger,
		private IL10N $l10n,
		private IConfig $config,
		private IAppManager $appManager,
		private IURLGenerator $urlGenerator,
		IClientService $clientService,
	) {
		$this->client = $clientService->newClient();
	}

	private function getExApp() {
		if (!class_exists('\OCA\AppAPI\Db\ExApp')) {
			$this->logger->error('ExApp class not found, please install the AppAPI app from the Nextcloud AppStore');
			return null;
		}

		$exApp = \OCP\Server::get(\OCA\AppAPI\Service\AppAPIService::class)->getExApp('schackles');
		return $exApp;
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

		$exApp = $this->getExApp();
		if ($exApp === null) {
			return;
		}
		$this->requestToExApp($userId, $exApp, '/deleteSources', 'POST', $params);
	}

	/**
	 * @param string $userId
	 * @param Source[] $sources
	 * @return void
	 */
	public function indexSources(string $userId, array $sources): void {
		if (count($sources) === 0) {
			return;
		}

		$params = array_map(function (Source $source) {
			return [
				'name' => 'sources',
				'filename' => $source->reference, // 'file: 555'
				'contents' => $source->content,
				'headers' => [
					'userId' => $source->userId,
					'title' => $source->title,
					'type' => $source->type,
					'modified' => $source->modified,
				],
			];
		}, $sources);

		$exApp = $this->getExApp();
		if ($exApp === null) {
			return;
		}
		$this->requestToExApp($userId, $exApp, '/loadSources', 'PUT', $params, 'multipart/form-data');
	}

	public function query(string $userId, string $prompt, bool $useContext = true): array {
		$params = [
			'query' => $prompt,
			'userId' => $userId,
			'useContext' => $useContext,
		];

		$exApp = $this->getExApp();
		if ($exApp === null) {
			return ['error' => $this->l10n->t('ExApp class not found, please install the AppAPI app from the Nextcloud AppStore')];
		}

		$response = $this->requestToExApp($userId, $exApp, '/query', 'GET', $params);

		if (isset($response['error'])) {
			return $response;
		}

		return ['message' => $this->getWithPresentableSources($response['output'] ?? '', ...($response['sources'] ?? []))];
	}

	private static function getExAppUrl(string $protocol, string $host, int $port): string {
		return sprintf('%s://%s:%s', $protocol, $host, $port);
	}

	/**
	 * Request to ExApp with AppAPI auth headers
	 *
	 * @param string|null $userId
	 * @param \OCA\AppAPI\Db\ExApp $exApp
	 * @param string $route
	 * @param string $method
	 * @param array $params
	 * @return array|resource|string
	 */
	public function requestToExApp(
		?string $userId,
		\OCA\AppAPI\Db\ExApp $exApp,
		string $route,
		string $method = 'POST',
		array $params = [],
		?string $contentType = null,
	): mixed {
		try {
			$url = self::getExAppUrl(
				$exApp->getProtocol(),
				$exApp->getHost(),
				$exApp->getPort()
			) . $route;

			$timeout = $this->config->getAppValue(Application::APP_ID, 'request_timeout', Application::CC_DEFAULT_REQUEST_TIMEOUT) ?: Application::CC_DEFAULT_REQUEST_TIMEOUT;

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
		} catch (\Throwable $e) {
			$this->logger->error(
				sprintf('Error during request to ExApp %s: %s', $exApp->getAppid(), $e->getMessage()),
				['exception' => $e]
			);
			return [
				'error' => $this->l10n->t('Error during request to ExApp') . $exApp->getAppid() . ': ' . $e->getMessage()
			];
		}
	}

	public function getWithPresentableSources(string $llmResponse, string ...$sourceRefs): string {
		if (count($sourceRefs) === 0) {
			return $llmResponse;
		}

		$output = str_repeat(PHP_EOL, 3) . $this->l10n->t('Sources referenced to generate the above response:') . PHP_EOL;

		foreach ($sourceRefs as $source) {
			if (str_starts_with($source, 'file: ') && is_numeric($fileId = substr($source, 6))) {
				$output .= $this->urlGenerator->linkToRouteAbsolute('files.View.showFile', ['fileid' => $fileId]) . PHP_EOL;
			}
		}

		return $llmResponse . $output;
	}
}
