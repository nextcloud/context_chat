<?php

namespace OCA\ContextChat\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCP\Exceptions\AppConfigTypeConflictException;
use Psr\Log\LoggerInterface;

class DiagnosticService {

	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

    /**
     * @return array
     * @throws AppConfigTypeConflictException
     */
	public function getBackgroundJobDiagnostics(): array {
		return $this->appConfig->getAppValueArray('background_jobs_diagnostics', [], true);
	}

	/**
	 * @param array $value
	 * @return void
	 * @throws AppConfigTypeConflictException
	 * @throws \JsonException
	 */
	private function setBackgroundJobDiagnostics(array $value): void {
		$this->appConfig->setAppValueArray('background_jobs_diagnostics', $value, true);
	}

	/**
	 * @param string $class
	 * @param int $id
	 * @return void
	 */
	public function sendHeartbeat(string $class, int $id): void {
		$key = $class . '-' . $id;
        try {
            $diagnostics = $this->getBackgroundJobDiagnostics();
            if (!isset($diagnostics[$key])) {
                $diagnostics[$key] = [];
			}
			$diagnostics[$key] = array_merge(['last_seen' => time()], $diagnostics[$key]);
			$this->setBackgroundJobDiagnostics($diagnostics);
		} catch (\OCP\Exceptions\AppConfigTypeConflictException|\JsonException  $e) {
			$this->logger->warning('Error during context chat diagnostic heartbeat', ['exception' => $e]);
		}
	}
}
