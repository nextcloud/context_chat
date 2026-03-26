<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCA\ContextChat\Db\FsEventMapper;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Logger;
use OCP\AppFramework\Services\IAppConfig;
use OCP\DB\Exception;
use OCP\Util;

class StatisticsService {
	public function __construct(
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

	public function getStatistics(): array {
		$stats = [];

		$stats['installed_at'] = $this->appConfig->getAppValueInt('installed_time', 0, lazy: true);
		if ($this->appConfig->getAppValueInt('last_indexed_time', 0, lazy: true) === 0) {
			$stats['initial_indexing_complete'] = false;
		} else {
			$stats['initial_indexing_complete'] = true;
			$stats['intial_indexing_completed_at'] = $this->appConfig->getAppValueInt('last_indexed_time', 0, lazy: true);
		}

		try {
			$stats['eligible_files_count'] = $this->storageService->countFiles();
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			$stats['eligible_files_count'] = 0;
		}
		$stats['recorded_indexed_files_count'] = Util::numericToNumber(intval(
			$this->appConfig->getAppValueString('indexed_files_count', '0', lazy: true)
		));
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

		return $stats;
	}
}
