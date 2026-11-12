<?php

/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\Service;

use OCP\AppFramework\Services\IAppConfig;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\SystemTag\ISystemTagObjectMapper;

/**
 * Decides whether a file may be indexed, based on the index mode and on the
 * allow/deny system tags chosen by the admin.
 *
 * Index mode "all": everything is indexed, tags are ignored.
 * Index mode "tag_only" (= "consider tags"):
 *   - allow list set, deny list empty: index what has an allow tag
 *   - allow list empty, deny list set: index everything except what has a deny tag
 *   - both set: index what has an allow tag and has no deny tag (deny wins)
 * A tag on a folder applies to everything inside it.
 *
 * If the mode is "tag_only" but both lists are empty (the admin UI refuses to save
 * that), no rule exists and everything counts as eligible, so that a broken config
 * can never wipe the index.
 */
class TagEligibilityService {
	private const MAX_DEPTH = 50;

	public function __construct(
		private IAppConfig $appConfig,
		private ISystemTagObjectMapper $tagMapper,
		private StorageService $storageService,
	) {
	}

	/** @return string[] */
	public function getAllowTagIds(): array {
		return $this->readIds('tag_allow_ids');
	}

	/** @return string[] */
	public function getDenyTagIds(): array {
		return $this->readIds('tag_deny_ids');
	}

	public function usesTags(): bool {
		$mode = $this->appConfig->getAppValueString('index_mode', 'all', lazy: true);
		if ($mode !== 'tag_only') {
			return false;
		}
		return $this->getAllowTagIds() !== [] || $this->getDenyTagIds() !== [];
	}

	/**
	 * Is this single node (file or folder) eligible for indexing?
	 */
	public function isEligible(Node $node): bool {
		if (!$this->usesTags()) {
			return true;
		}
		try {
			$tags = $this->tagsOnNodeAndAncestors($node);
		} catch (\Throwable $e) {
			return false;
		}
		return $this->decide(
			$this->getAllowTagIds(),
			$this->getDenyTagIds(),
			$this->hasAny($tags, $this->getAllowTagIds()),
			$this->hasAny($tags, $this->getDenyTagIds()),
		);
	}

	/**
	 * Ids of all files inside $folder that are eligible for indexing.
	 *
	 * @return int[]
	 */
	public function eligibleFileIds(Folder $folder): array {
		$fileIds = $this->storageService->getAllFilesInFolder($folder);
		if (!$this->usesTags()) {
			return $fileIds;
		}

		$allow = $this->getAllowTagIds();
		$deny = $this->getDenyTagIds();

		try {
			$tagsAbove = $this->tagsOnNodeAndAncestors($folder);
		} catch (\Throwable $e) {
			return [];
		}
		$allowAbove = $allow === [] || $this->hasAny($tagsAbove, $allow);
		$denyAbove = $deny !== [] && $this->hasAny($tagsAbove, $deny);
		if ($denyAbove) {
			return [];
		}

		// null means "covered everywhere inside the folder"
		$allowCovered = $allowAbove ? null : $this->filesCoveredByTags($folder, $allow);
		$denyCovered = $deny === [] ? [] : $this->filesCoveredByTags($folder, $deny);

		$result = [];
		foreach ($fileIds as $fileId) {
			if (isset($denyCovered[$fileId])) {
				continue;
			}
			if ($allowCovered !== null && !isset($allowCovered[$fileId])) {
				continue;
			}
			$result[] = $fileId;
		}
		return $result;
	}

	private function decide(array $allow, array $deny, bool $hasAllow, bool $hasDeny): bool {
		$allowOk = $allow === [] || $hasAllow;
		$denyOk = $deny === [] || !$hasDeny;
		return $allowOk && $denyOk;
	}

	/**
	 * Tag ids on the node itself and on every parent folder, with one DB call.
	 *
	 * @return string[]
	 */
	private function tagsOnNodeAndAncestors(Node $node): array {
		$ids = [];
		$current = $node;
		$depth = 0;
		while ($current !== null && $depth < self::MAX_DEPTH) {
			$ids[] = (string)$current->getId();
			if ($current->getPath() === '/' || $current->getInternalPath() === '') {
				break;
			}
			try {
				$current = $current->getParent();
			} catch (\Throwable $e) {
				break;
			}
			$depth++;
		}

		$tags = [];
		foreach ($this->tagMapper->getTagIdsForObjects($ids, 'files') as $tagIds) {
			foreach ($tagIds as $tagId) {
				$tags[(string)$tagId] = true;
			}
		}
		return array_keys($tags);
	}

	/**
	 * Files inside $folder covered by any of the given tags (tagged file itself,
	 * or file inside a tagged sub folder).
	 *
	 * @param string[] $tagIds
	 * @return array<int,true>
	 */
	private function filesCoveredByTags(Folder $folder, array $tagIds): array {
		$covered = [];
		foreach ($tagIds as $tagId) {
			foreach ($this->tagMapper->getObjectIdsForTags([$tagId], 'files') as $objectId) {
				$node = $folder->getFirstNodeById((int)$objectId);
				if ($node === null) {
					continue;
				}
				if ($node instanceof Folder) {
					foreach ($this->storageService->getAllFilesInFolder($node) as $fileId) {
						$covered[$fileId] = true;
					}
				} else {
					$covered[$node->getId()] = true;
				}
			}
		}
		return $covered;
	}

	/**
	 * @param string[] $tags
	 * @param string[] $wanted
	 */
	private function hasAny(array $tags, array $wanted): bool {
		return array_intersect($wanted, $tags) !== [];
	}

	/** @return string[] */
	private function readIds(string $key): array {
		$raw = $this->appConfig->getAppValueString($key, '[]', lazy: true);
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return [];
		}
		return array_values(array_unique(array_map('strval', $decoded)));
	}
}
