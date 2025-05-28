<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContextChat\TaskProcessing;

use OCA\ContextChat\AppInfo\Application;
use OCP\IL10N;
use OCP\TaskProcessing\EShapeType;
use OCP\TaskProcessing\ITaskType;
use OCP\TaskProcessing\ShapeDescriptor;

class ContextChatSearchTaskType implements ITaskType {
	public const ID = Application::APP_ID . ':context_chat_search';

	public function __construct(
		private IL10N $l,
	) {
	}

	/**
	 * @inheritDoc
	 * @since 2.3.0
	 */
	public function getName(): string {
		return $this->l->t('Context Chat search');
	}

	/**
	 * @inheritDoc
	 * @since 2.3.0
	 */
	public function getDescription(): string {
		return $this->l->t('Search with Context Chat.');
	}

	/**
	 * @return string
	 * @since 2.3.0
	 */
	public function getId(): string {
		return self::ID;
	}

	/**
	 * @return ShapeDescriptor[]
	 * @since 2.3.0
	 */
	public function getInputShape(): array {
		return [
			'prompt' => new ShapeDescriptor(
				$this->l->t('Prompt'),
				$this->l->t('Search your documents, files and more'),
				EShapeType::Text,
			),
			'scopeType' => new ShapeDescriptor(
				$this->l->t('Scope type'),
				$this->l->t('none, provider'),
				EShapeType::Text,
			),
			'scopeList' => new ShapeDescriptor(
				$this->l->t('Scope list'),
				$this->l->t('list of providers'),
				EShapeType::ListOfTexts,
			),
			'scopeListMeta' => new ShapeDescriptor(
				$this->l->t('Scope list metadata'),
				$this->l->t('Required to nicely render the scope list in assistant'),
				EShapeType::Text,
			),
			'limit' => new ShapeDescriptor(
				$this->l->t('Max result number'),
				$this->l->t('Maximum number of results returned by Context Chat'),
				EShapeType::Number,
			),
		];
	}

	/**
	 * @return ShapeDescriptor[]
	 * @since 2.3.0
	 */
	public function getOutputShape(): array {
		return [
			// each string is a json encoded object
			// { id: string, label: string, icon: string, url: string }
			'sources' => new ShapeDescriptor(
				$this->l->t('Sources'),
				$this->l->t('The sources that were found'),
				EShapeType::ListOfTexts,
			),
		];
	}
}
