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
 * `submitContent(string $appId, array $items)` Providers can use this to submit content for indexing in context chat
 * `removeContentForUsers(string $appId, string $providerId, string $itemId, array $users)` Remove a content item from the knowledge base of context chat for specified users
 * `removeAllContentForUsers(string $appId, string $providerId, array $users)` Remove all content items from the knowledge base of context chat for specified users

To register your content provider, your app needs to listen to the `OCA\ContextChat\Event\ContentProviderRegisterEvent` event and call the `registerContentProvider` method in the event for every provider you want to register.
Any interaction with the content manager using the above listed methods should automatically register the provider.

You may call the `registerContentProvider` method explicitly if you want to trigger an initial import of content items.

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
