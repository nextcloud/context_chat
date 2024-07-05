<?php

declare(strict_types=1);

namespace OCA\ContextChat\TaskProcessing;

use OCA\ContextChat\AppInfo\Application;
use OCA\ContextChat\Service\LangRopeService;
use OCP\IL10N;
use OCP\TaskProcessing\ISynchronousProvider;
use OCP\TaskProcessing\TaskTypes\TextToText;

class TextToTextProvider implements ISynchronousProvider {

	public function __construct(
		private LangRopeService $langRopeService,
		private IL10N $l10n,
		private ?string $userId,
	) {
	}

	public function getId(): string {
		return Application::APP_ID . '-text2text';
	}

	public function getName(): string {
		return $this->l10n->t('Nextcloud Assistant Context Chat Text Generation Provider');
	}

	public function getTaskTypeId(): string {
		return TextToText::ID;
	}

	public function getExpectedRuntime(): int {
		return 30;
	}

	public function getOptionalInputShape(): array {
		return [];
	}

	public function getOptionalOutputShape(): array {
		return [];
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		if ($this->userId === null) {
			throw new \RuntimeException('User ID is required to process the prompt.');
		}

		if (!isset($input['input']) || !is_string($input['input'])) {
			throw new \RuntimeException('Invalid prompt');
		}

		$response = $this->langRopeService->query($this->userId, $input['input'], false);
		if (isset($response['error'])) {
			throw new \RuntimeException('No result in ContextChat response. ' . $response['error']);
		}
		return $response;
	}
}
