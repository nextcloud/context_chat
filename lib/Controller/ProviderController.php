<?php
/**
 * @copyright Copyright (c) 2024 Anupam Kumar <kyteinsky@gmail.com>
 *
 * @author Anupam Kumar <kyteinsky@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\ContextChat\Controller;

use OCA\ContextChat\Service\ProviderService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ProviderController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private ProviderService $providerService,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getDefaultProviderKey(): DataResponse {
		$providerKey = $this->providerService->getDefaultProviderKey();
		return new DataResponse($providerKey);
	}

	/**
	 * @return DataResponse
	 */
	#[NoAdminRequired]
	public function getProviders(): DataResponse {
		$providers = $this->providerService->getEnrichedProviders();
		return new DataResponse($providers);
	}
}
