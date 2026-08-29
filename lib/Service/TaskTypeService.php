<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\Logger;
use OCP\AppFramework\Services\IAppConfig;
use OCP\TaskProcessing\IManager as TaskProcessingManager;
use OCA\ContextChat\AppInfo\Application;

class TaskTypeService {
	public const OCR_TASK_TYPE = 'core:image2text:ocr';
	public const SPEECH_TO_TEXT_TASK_TYPE = 'core:audio2text';

	public function __construct(
		private Logger $logger,
		private TaskProcessingManager $taskProcessingManager,
		private IAppConfig $appConfig,
	) {
	}

	public function isOcrTaskTypeAvailable(): bool {
		try {
			$this->taskProcessingManager->getPreferredProvider(self::OCR_TASK_TYPE);
			return true;
		} catch (\Exception $e) {
			$this->logger->debug('OCR task type is not available: ' . $e->getMessage());
			return false;
		}
	}

	public function isSpeechToTextTaskTypeAvailable(): bool {
		try {
			$this->taskProcessingManager->getPreferredProvider(self::SPEECH_TO_TEXT_TASK_TYPE);
			return true;
		} catch (\Exception $e) {
			$this->logger->debug('Speech-to-text task type is not available: ' . $e->getMessage());
			return false;
		}
	}

	/**
	 * @return list<string>
	 */
	public function getMultimodalMimetypes(bool $includingTextual = true): array {
		$multimodalEnabled = $this->appConfig->getAppValueBool(MultimodalService::MULTIMODAL_CONFIG_KEY, false, lazy: true);
		if (!$multimodalEnabled) {
			return $includingTextual ? Application::MIMETYPES : [];
		}

		$imagesEnabled = $this->isOcrTaskTypeAvailable();
		$audioEnabled = $this->isSpeechToTextTaskTypeAvailable();
		return array_merge(
			$includingTextual ? Application::MIMETYPES : [],
			$imagesEnabled ? Application::IMAGE_MIMETYPES : [],
			$audioEnabled ? Application::AUDIO_MIMETYPES : []
		);
	}
}
