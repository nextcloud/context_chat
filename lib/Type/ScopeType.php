<?php
/**
 * Nextcloud - ContextChat
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Anupam Kumar <kyteinsky@gmail.com>
 * @copyright Anupam Kumar 2023
 */

declare(strict_types=1);

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
