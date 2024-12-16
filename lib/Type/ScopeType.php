<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Type;

class ScopeType {
	public const NONE = 'none';
	public const PROVIDER = 'provider';
	public const SOURCE = 'source';

	public static function validate(string $scopeType): void {
		$relection = new \ReflectionClass(self::class);
		if (!in_array($scopeType, $relection->getConstants())) {
			throw new \InvalidArgumentException(
				"Invalid scope type: {$scopeType}, should be one of: [" . implode(', ', $relection->getConstants()) . ']'
			);
		}
	}
}
