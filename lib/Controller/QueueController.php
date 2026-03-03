<?php


declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Controller;

use OCA\ContextChat\Db\QueueActionMapper;
use OCA\ContextChat\Db\QueueContentItemMapper;
use OCA\ContextChat\Db\QueueMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\ExAppRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\DB\Exception;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class QueueController extends OCSController {
	public function __construct(
		$appName,
		IRequest $request,
		$corsMethods = 'PUT, POST, GET, DELETE, PATCH',
		$corsAllowedHeaders = 'Authorization, Content-Type, Accept, OCS-APIRequest',
		$corsMaxAge = 1728000,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request, $corsMethods, $corsAllowedHeaders, $corsMaxAge);
	}

	/**
	 * ExApp-only endpoint to retrieve file contents by fileId
	 * @param IRootFolder $rootFolder
	 * @param int $fileId
	 * @return DataResponse|Http
	 */
	#[ExAppRequired]
	#[ApiRoute(verb: 'GET', url: '/files/{fileId}')]
	public function getFileContents(IRootFolder $rootFolder, int $fileId) : DataResponse|Http\StreamResponse {
		$file = $rootFolder->getFirstNodeById($fileId);
		if (!$file) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		$stream = $file->fopen('r');
		if (!$stream) {
			return new DataResponse([], Http::STATUS_NOT_FOUND);
		}
		return new Http\StreamResponse($stream);
	}

	/**
	 * ExApp-only endpoint to retrieve items from documents queues
	 * @param QueueMapper $queueMapper
	 * @param QueueContentItemMapper $queueContentItemMapper
	 * @param int $n
	 * @return DataResponse
	 */
	#[ExAppRequired]
	#[ApiRoute(verb: 'GET', url: '/queues/documents/')]
	public function getDocumentsQueueItems(QueueMapper $queueMapper, QueueContentItemMapper $queueContentItemMapper, int $n = 64) : DataResponse {
		try {
			$files = [];
			while (count($files) < $n) {
				$limit = $n - count($files);
				$documents = $queueMapper->getFromQueue($limit);
				if (empty($documents)) {
					break;
				}
				foreach ($documents as $document) {
					if ($queueMapper->lock($document->getId())) {
						$files[] = $document;
					}
				}
			}
			$contentItems = [];
			while (count($contentItems) < $n) {
				$limit = $n - count($contentItems);
				$documents = $queueContentItemMapper->getFromQueue($limit);
				if (empty($documents)) {
					break;
				}
				foreach ($documents as $document) {
					if ($queueContentItemMapper->lock($document->getId())) {
						$contentItems[] = $document;
					}
				}
			}
			return new DataResponse([
				'files' => $files,
				'content_providers' => $contentItems,
			]);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * ExApp-only endpoint for backend to remove items from documents queues
	 * @param IDBConnection $db
	 * @param QueueMapper $queueMapper
	 * @param QueueContentItemMapper $queueContentItemMapper
	 * @param list<int> $files
	 * @param list<int> $content_providers
	 * @return DataResponse
	 */
	#[ExAppRequired]
	#[ApiRoute(verb: 'DELETE', url: '/queues/documents/')]
	public function deleteDocumentsQueueItems(IDBConnection $db, QueueMapper $queueMapper, QueueContentItemMapper $queueContentItemMapper, array $files, array $content_providers) : DataResponse {
		try {
			$db->beginTransaction();
			$queueMapper->removeFromQueue($files);
			$queueContentItemMapper->removeFromQueue($content_providers);
			$db->commit();
			return new DataResponse();
		} catch (Exception $e) {
			try {
				$db->rollBack();
			} catch (Exception $e) {
				$this->logger->error($e->getMessage(), ['exception' => $e]);
			}
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Admin-only Stats endpoint for external auto-scalers
	 * @return DataResponse
	 */
	#[ApiRoute(verb: 'GET', url: '/queues/documents/stats')]
	#[Http\Attribute\NoCSRFRequired]
	public function countDocumentsQueueItems(QueueMapper $queueMapper, QueueContentItemMapper $contentItemMapper) : DataResponse {
		try {
			$count = $queueMapper->count();
			foreach ($contentItemMapper->count() as $providerCount) {
				$count += $providerCount;
			}
			$locked = $queueMapper->countLocked();
			foreach ($contentItemMapper->countLocked() as $providerCount) {
				$locked += $providerCount;
			}
			return new DataResponse([ 'scheduled' => $count, 'running' => $locked ]);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * ExApp-only endpoint for backend to get actions from queue
	 * @param QueueActionMapper $queueActionMapper
	 * @param int $n
	 * @return DataResponse
	 */
	#[ExAppRequired]
	#[ApiRoute(verb: 'GET', url: '/queues/actions/')]
	public function getActionsQueueItems(QueueActionMapper $queueActionMapper, int $n = 512) : DataResponse {
		try {
			$actions = [];
			while (count($actions) < $n) {
				$limit = $n - count($actions);
				$documents = $queueActionMapper->getFromQueue($limit);
				if (empty($documents)) {
					break;
				}
				foreach ($documents as $document) {
					if ($queueActionMapper->lock($document->getId())) {
						$actions[] = $document;
					}
				}
			}
			return new DataResponse(['actions' => $actions]);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * ExApp-only endpoint for backend to remove items from actions queue
	 * @param IDBConnection $db
	 * @param QueueActionMapper $queueActionMapper
	 * @param list<int> $actions
	 * @return DataResponse
	 */
	#[ExAppRequired]
	#[ApiRoute(verb: 'DELETE', url: '/queues/actions/')]
	public function deleteActionsQueueItems(IDBConnection $db, QueueActionMapper $queueActionMapper, array $actions) : DataResponse {
		try {
			$db->beginTransaction();
			$queueActionMapper->removeFromQueue($actions);
			$db->commit();
			return new DataResponse();
		} catch (Exception $e) {
			try {
				$db->rollBack();
			} catch (Exception $e) {
				$this->logger->error($e->getMessage(), ['exception' => $e]);
			}
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	/**
	 * Admin-only Stats endpoint for external auto-scalers
	 * @return DataResponse
	 */
	#[ApiRoute(verb: 'GET', url: '/queues/actions/stats')]
	#[Http\Attribute\NoCSRFRequired]
	public function countActionsQueueItems(QueueActionMapper $queueActionMapper) : DataResponse {
		try {
			$count = $queueActionMapper->count();
			$locked = $queueActionMapper->countLocked();
			return new DataResponse([ 'scheduled' => $count, 'running' => $locked ]);
		} catch (Exception $e) {
			$this->logger->error($e->getMessage(), ['exception' => $e]);
			return new DataResponse([], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
