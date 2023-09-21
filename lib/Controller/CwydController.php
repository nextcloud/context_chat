<?php
/**
 * Nextcloud - Cwyd
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <julien-nc@posteo.net>
 * @copyright Julien Veyssier 2022
 */

namespace OCA\Cwyd\Controller;

use OCA\Cwyd\Service\LangRopeService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class CwydController extends Controller {

	public function __construct(
		string                   $appName,
		IRequest                 $request,
		private LangRopeService $langRopeService,
		private ?string          $userId
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @param string $prompt
	 * @param int|null $n
	 * @param int|null $maxTokens
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function query(string $prompt, ?int $n = null, ?int $maxTokens = null): DataResponse {
		$response = $this->langRopeService->query($this->userId, $prompt);
		if (isset($response['error'])) {
			return new DataResponse($response, Http::STATUS_BAD_REQUEST);
		}
		return new DataResponse($response);
	}
}
