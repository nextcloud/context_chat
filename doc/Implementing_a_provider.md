<!--
  - SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

> [!IMPORTANT]
> As of Nextcloud 32, the internal API described in this document has been replaced with the OCP API.
>
> For more details, see the [Nextcloud documentation](https://docs.nextcloud.com/server/latest/developer_manual/digging_deeper/context_chat.html).

# How to implement a content provider for Context Chat

### The content provider interface
A content provider for context chat needs to implement the `\OCA\ContextChat\Public\IContentProvider` interface:

```php
/**
 * This interface defines methods to implement a content provider
 * @since 1.1.0
 */
interface IContentProvider {
	/**
	 * The ID of the provider
	 *
	 * @return string
	 * @since 1.1.0
	 */
	public function getId(): string;

	/**
	 * The ID of the app making the provider avaialble
	 *
	 * @return string
	 * @since 1.1.0
	 */
	public function getAppId(): string;

	/**
	 * The absolute URL to the content item
	 *
	 * @param string $id
	 * @return string
	 * @since 1.1.0
	 */
	public function getItemUrl(string $id): string;

	/**
	 * Starts the initial import of content items into content chat
	 *
	 * @return void
	 * @since 1.1.0
	 */
	public function triggerInitialImport(): void;
}
```

The `triggerInitialImport` method is called when context chat is first set up and allows your app to import all existing content into context chat in one bulk. Any other items that are created afterwards will need to be added on demand.

### The content manager service
To add content and register your provider implementation you will need to use the `\OCA\ContextChat\Public\ContentManager` service.

The ContentManager has the following methods:

 * `registerContentProvider(string $providerClass)`
 * `submitContent(string $appId, array $items)` Providers can use this to submit content for indexing in context chat.
 * `removeContentForUsers(string $appId, string $providerId, string $itemId, array $users)` Remove a content item from the knowledge base of context chat for specified users. (deprecated)
 * `removeAllContentForUsers(string $appId, string $providerId, array $users)` Remove all content items from the knowledge base of context chat for specified users. (deprecated)
 * `updateAccess(string $appId, string $providerId, string $itemId, string $op, array $userIds)` Update the access rights for a content item. Use \OCA\ContextChat\Public\UpdateAccessOp constants for the $op operation.
 * `updateAccessProvider(string $appId, string $providerId, string $op, array $userIds)` Update the access rights for all content items of a provider. Use \OCA\ContextChat\Public\UpdateAccessOp constants for the $op operation.
 * `updateAccessDeclarative(string $appId, string $providerId, string $itemId, array $userIds)` Update the access rights for a content item. This method is declarative and will replace the current access rights with the provided ones.
 * `deleteProvider(string $appId, string $providerId)` Remove all content items of a provider from the knowledge base of context chat.
 * `deleteContent(string $appId, string $providerId, array $itemIds)` Remove content items from the knowledge base of context chat.

### The event implementation
To register your content provider, your app needs to listen to the `OCA\ContextChat\Event\ContentProviderRegisterEvent` event and call the `registerContentProvider` method in the event for every provider you want to register.

#### Application.php (partially for reference)
```php
use OCA\ContextChat\Event\ContentProviderRegisterEvent;
use OCA\xxx\ContextChat\ContentProvider;
...
$context->registerEventListener(ContentProviderRegisterEvent::class, ContentProvider::class);
```

#### ContentProvider (partially for reference)
```php
class ContentProvider implements IContentProvider {
...
public function handle(Event $event): void {
	if (!$event instanceof ContentProviderRegisterEvent) {
		return;
	}
	$event->registerContentProvider(***appId***, ***providerId***', ContentProvider::class);
}
```

Any interaction with the content manager using the Content Manager's methods or listing the providers in the Assistant should automatically register the provider.

You may call the `registerContentProvider` method explicitly if you want to trigger an initial import of content items.

### The content item
To submit content, wrap it in a `\OCA\ContextChat\Public\ContentItem` object:

```php
new ContentItem(
		string $itemId,
		string $providerId,
		string $title,
		string $content,
		string $documentType,
		\DateTime $lastModified,
		array $users,
	)
```

`documentType` is a natural language term for your document type in English, e.g. `E-Mail` or `Bookmark`.

### Note

1. Ensure the item IDs are unique across all users for a given provider.
2. App ID and provider ID cannot contain double underscores `__`, spaces ` `, or colons `:`.
