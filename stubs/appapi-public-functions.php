<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI {
	class PublicFunctions {
		public function __construct(
			private \OCA\AppAPI\Service\ExAppService $exAppService,
			private \OCA\AppAPI\Service\AppAPIService $service,
		) {
		}

		/**
		 * Request to ExApp with AppAPI auth headers
		 */
		public function exAppRequest(
			string $appId,
			string $route,
			?string $userId = null,
			string $method = 'POST',
			array $params = [],
			array $options = [],
			?\OCP\IRequest $request = null,
		): array|\OCP\Http\Client\IResponse {
		}

		/**
		 * Request to ExApp with AppAPI auth headers and ExApp user initialization
		 */
		public function exAppRequestWithUserInit(
			string $appId,
			string $route,
			string $userId,
			string $method = 'POST',
			array $params = [],
			array $options = [],
			?\OCP\IRequest $request = null,
		): array|\OCP\Http\Client\IResponse {
		}
	}
}
