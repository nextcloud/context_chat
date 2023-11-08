<?php

declare(strict_types=1);
namespace OCA\Cwyd\TextProcessing;

use OCA\Cwyd\Service\LangRopeService;
use OCP\IL10N;
use OCP\TextProcessing\IProvider;

class CwydTextProcessingProvider implements IProvider {

	public function __construct(
		private LangRopeService $langRopeService,
		private IL10N $l10n,
		private ?string $userId,
	) {
	}

	public function getName(): string {
		return $this->l10n->t('Cwyd');
	}

	public function process(string $prompt): string {
		$response = $this->langRopeService->query($this->userId, $prompt);
		if (isset($response['result']) && $response['result']) {
			return $response['result'];
		}
		throw new \Exception('No result in Cwyd response. ' . ($response['error'] ?? ''));
	}

	public function getTaskType(): string {
		return CwydTaskType::class;
	}
}
