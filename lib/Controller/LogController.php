<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Controller;

use OCA\ContextChat\Logger;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;

use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TextPlainResponse;
use OCP\AppFramework\Http\ZipResponse;
use OCP\IRequest;

class LogController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private Logger $logger,
	) {
		parent::__construct($appName, $request);
	}
	/**
	 * Downloads log file
	 *
	 * @return Response
	 */
	#[NoCSRFRequired]
	public function getNextcloudLogs(): Response {
		$logFilepath = $this->logger->getLogFilepath();
		$response = new ZipResponse($this->request, 'nextcloud-logs');
		$filePath = $logFilepath;
		$counter = 1;
		while ($remoteFile = fopen($filePath, 'r')) {
			$size = filesize($filePath);
			$time = filemtime($filePath); // Needed otherwise tar complains that time is in the future
			$response->addResource($remoteFile, basename($filePath), $size, $time);
			$filePath = $logFilepath . '.' . $counter++;
		}
		if ($counter == 1) {
			return new TextPlainResponse('No log file found', Http::STATUS_NOT_FOUND);
		}
		return $response;
	}
}
