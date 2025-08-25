<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\ContextChat\Settings;

use OCA\ContextChat\Db\FsEventMapper;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Logger;
use OCA\ContextChat\Service\ActionScheduler;
use OCA\ContextChat\Service\LangRopeService;
use OCA\ContextChat\Service\ProviderConfigService;
use OCA\ContextChat\Service\QueueService;
use OCA\ContextChat\Service\StorageService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IAppConfig;
use OCP\AppFramework\Services\IInitialState;
use OCP\DB\Exception;
use OCP\Settings\ISettings;
use OCP\Util;

class AdminSettings implements ISettings {
	public function __construct(
		private IInitialState $initialState,
		private IAppConfig $appConfig,
		private ActionScheduler $actionService,
		private QueueService $queueService,
		private StorageService $storageService,
		private LangRopeService $langRopeService,
		private QueueContentItemMapper $contentQueue,
		private Logger $logger,
		private FsEventMapper $fsEventMapper,
	) {
	}

	/**
	 * @return TemplateResponse
	 */
	public function getForm(): TemplateResponse {
		$stats = [];

		$stats['installed_at'] = $this->appConfig->getAppValueInt('installed_time', 0);
		if ($this->appConfig->getAppValueInt('last_indexed_time', 0) === 0) {
			$stats['initial_indexing_complete'] = false;
		} else {
			$stats['initial_indexing_complete'] = true;
			$stats['intial_indexing_completed_at'] = $this->appConfig->getAppValueInt('last_indexed_time', 0);
		}

		try {
			$stats['eligible_files_count'] = $this->storageService->countFiles();
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			$stats['eligible_files_count'] = 0;
		}
		$stats['recorded_indexed_files_count'] = Util::numericToNumber($this->appConfig->getAppValueString('indexed_files_count', '0'));
		try {
			$stats['queued_actions_count'] = $this->actionService->count();
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			$stats['queued_actions_count'] = 0;
		}
		try {
			$stats['queued_fs_events_count'] = $this->fsEventMapper->count();
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			$stats['queued_fs_events_count'] = 0;
		}
		try {
			$stats['vectordb_document_counts'] = $this->langRopeService->getIndexedDocumentsCounts();
			$stats['backend_available'] = true;
		} catch (\RuntimeException $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			$stats['backend_available'] = false;
			$stats['vectordb_document_counts'] = [ ProviderConfigService::getDefaultProviderKey() => 0 ];
		}
		try {
			$queued_files_count = $this->queueService->count();
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			$queued_files_count = 0;
		}
		try {
			$stats['queued_new_files_count'] = $this->queueService->countNewFiles();
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			$stats['queued_new_files_count'] = 0;
		}
		try {
			$stats['queued_documents_counts'] = $this->contentQueue->count();
			$stats['queued_documents_counts'][ProviderConfigService::getDefaultProviderKey()] = $queued_files_count;
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			$stats['queued_documents_counts'] = [];
		}

		$this->initialState->provideInitialState('stats', $stats);

		return new TemplateResponse('context_chat', 'admin');
	}

	/**
	 * @return string the section ID, e.g. 'sharing'
	 */
	public function getSection(): string {
		return 'ai';
	}

	/**
	 * @return int whether the form should be rather on the top or bottom of the admin section. The forms are arranged in ascending order of the priority values. It is required to return a value between 0 and 100.
	 */
	public function getPriority(): int {
		return 50;
	}
}
