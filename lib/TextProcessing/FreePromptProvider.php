<?php

declare(strict_types=1);
namespace OCA\Cwyd\TextProcessing;

use OCA\Cwyd\Service\LangRopeService;
use OCP\IL10N;
use OCP\TextProcessing\FreePromptTaskType;
use OCP\TextProcessing\IProvider;
use OCP\TextProcessing\IProviderWithUserId;

class FreePromptProvider implements IProvider, IProviderWithUserId {

	private ?string $userId = null;

	public function __construct(
		private LangRopeService $langRopeService,
		private IL10N $l10n,
	) {
	}

	public function getName(): string {
		return $this->l10n->t('Chat with your documents');
	}

	public function process(string $prompt): string {
		$response = $this->langRopeService->query($this->userId, $prompt);
		if (isset($response['result']) && $response['result']) {
			return $response['result'];
		}
		throw new \Exception('No result in Cwyd response. ' . ($response['error'] ?? ''));
	}

	public function getTaskType(): string {
		return FreePromptTaskType::class;
	}

	public function setUserId(?string $userId): void {
		$this->userId = $userId;
	}
}
