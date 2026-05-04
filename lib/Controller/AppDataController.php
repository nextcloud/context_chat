<?php


declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Controller;

use OCA\ContextChat\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\ApiRoute;
use OCP\AppFramework\Http\Attribute\ExAppRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class AppDataController extends OCSController {
	private const APP_DATA_FOLDER_NAME = 'temp_tp_files';
	private ?ISimpleFolder $appDataFolder = null;

	public function __construct(
		string $appName,
		IRequest $request,
		private LoggerInterface $logger,
		private IAppDataFactory $appDataFactory,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Stores files to the appdata and returns corresponding NC file ids
	 * @return DataResponse
	 */
	#[ExAppRequired]
	#[ApiRoute(verb: 'POST', url: '/upload_files')]
	public function uploadTempFile() : DataResponse {
		$files = $this->request->files;
		if (count($files) === 0) {
			return new DataResponse('No files provided.', Http::STATUS_BAD_REQUEST);
		}

		if (count($files) > Application::CC_MAX_FILES) {
			return new DataResponse(
				'No. of files exceeds permissible limit of ' . Application::CC_MAX_FILES,
				Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
			);
		}

		/** @var array<string, int> */
		$fileIds = [];
		/** @var array<string, array{message: string, code: int}> */
		$errors = [];

		foreach ($files as $filename => $file) {
			if ($file['size'] > Application::CC_MAX_SIZE) {
				$errors[$filename] = [
					'message' => 'Max file size exceeds permissible limit of ' . Application::CC_MAX_SIZE . ' bytes',
					'code' => Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
				];
			}

			if ($file['error'] !== 0) {
				$errors[$filename] = [
					'message' => 'Error in input file upload: ' . $file['error'],
					'code' => $file['error'] <= UPLOAD_ERR_NO_FILE
						? Http::STATUS_BAD_REQUEST
						: Http::STATUS_INTERNAL_SERVER_ERROR,
				];
			}

			if (empty($file)) {
				$errors[$filename] = [
					'message' => 'Invalid input data received',
					'code' => Http::STATUS_BAD_REQUEST,
				];
			}

			try {
				// tmp_name is the temporary filename of the file in which the uploaded file was stored on the server.
				$fileIds[$filename] = $this->storeTempFile($file['tmp_name']);
			} catch (NotPermittedException $e) {
				$this->logger->error('No permission to write the appdata folder: ' . $e->getMessage(), ['exception' => $e]);
				// this error message is fine since it's a ex-app only path
				return new DataResponse(
					'No permission to write the appdata folder: ' . $e->getMessage(),
					Http::STATUS_INTERNAL_SERVER_ERROR,
				);
			} catch (\Exception $e) {
				$this->logger->error('Failed to store input file: ' . $e->getMessage(), ['exception' => $e]);
				$errors[$filename] = [
					'message' => 'Failed to store the input file: ' . $e->getMessage(),
					'code' => Http::STATUS_INTERNAL_SERVER_ERROR,
				];
			}
		}

		return new DataResponse([
			'fileIds' => $fileIds,
			'errors' => (object)$errors,
		]);
	}

	/**
	 * @param string $tempFileLocation
	 * @return int file ID of the stored file
	 * @throws \RuntimeException
	 * @throws NotPermittedException
	 * @throws \OCP\DB\Exception
	 */
	private function storeTempFile(string $tempFileLocation): int {
		if ($this->appDataFolder === null) {
			$appDataFolder = $this->appDataFactory->get(Application::APP_ID);
			try {
				$this->appDataFolder = $appDataFolder->getFolder(self::APP_DATA_FOLDER_NAME);
			} catch (NotFoundException) {
				$this->appDataFolder = $appDataFolder->newFolder(self::APP_DATA_FOLDER_NAME);
			}
		}

		// file handle is closed in the newFile call
		$tempFileHandle = fopen($tempFileLocation, 'rb');
		if ($tempFileHandle === false) {
			throw new \RuntimeException('Failed to open temporary file');
		}

		$targetFileName = 'cc_temp_' . time() . '_' . random_int(100000, 999999);
		$targetFile = $this->appDataFolder->newFile($targetFileName, $tempFileHandle);

		return $targetFile->getId(); // polymorphic call to SimpleFile
	}
}
