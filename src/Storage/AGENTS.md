# Storage Module

Read-only access to the files of a directory tree. `Skills` and `Plugins` read their content through it, so they work on the local filesystem or on any custom backend.

## Interface

```php
interface StorageInterface
{
    /** Contents of the regular file, or null when missing, not a regular file, or outside the root. */
    public function read(string $path): ?string;

    /** A stream open for reading on the file, with the same null cases. A resource, not loaded into memory. */
    public function open(string $path): mixed;

    /** Names of the immediate children (files and directories) of a directory. */
    public function children(string $path): array;
}
```

- Paths are relative to the root and use `/` as separator. `''` addresses the root.
- All three methods return `null` (or an empty array) instead of throwing for anything they cannot read.
- `open()` is for the files not loaded into memory, such as the logos of a plugin (`Plugin::openAsset()`). PHP has no native `resource` type, hence the `mixed` declaration: the stream is a resource.

## Implementations

| Class | Backend | Notes |
|-------|---------|-------|
| `FileStorage` | Local filesystem | The root must exist, otherwise `StorageException`. Paths resolving outside the root, also through symlinks, are refused |

## Custom Implementations

```php
Skills::make(DatabaseStorage::make($pluginId));
Plugin::make(DatabaseStorage::make($pluginId));
```

A string is enough to get `FileStorage`. Storage objects are kept in the agent state and serialized with the tools (persisted interrupts, parallel branches): hold identifiers only and connect lazily, the way `McpConnector::__serialize()` does.

A storage without files on disk has no stream to hand out. Wrap the contents in a temporary one:

```php
public function open(string $path): mixed
{
    $content = $this->read($path);

    if ($content === null) {
        return null;
    }

    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $content);
    rewind($stream);

    return $stream;
}
```

## Path

`Path::normalize(string $path): ?string` turns a root-relative path into its canonical form (`a//b/./c` → `a/b/c`, `''` → root). It returns null for unsafe input: absolute paths, `\`, control characters and `..` segments.

`Skills`, the skill tool and `Plugin` normalize every path before handing it to a storage, so a custom implementation always receives a safe relative path. `FileStorage` still enforces the containment of the resolved paths itself.

## Dependencies

None. Self-contained.
