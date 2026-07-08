<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Diff\Changeset;

class FetchResult
{
    private string $rawContent;
    private string $hash;
    private bool $changed;
    private bool $delta;
    private ?Changeset $deltaChangeset;
    private array $meta;

    public function __construct(
        string $rawContent,
        string $hash,
        bool $changed = true,
        bool $delta = false,
        ?Changeset $deltaChangeset = null,
        array $meta = []
    ) {
        $this->rawContent = $rawContent;
        $this->hash = $hash;
        $this->changed = $changed;
        $this->delta = $delta;
        $this->deltaChangeset = $deltaChangeset;
        $this->meta = $meta;
    }

    /**
     * Returns raw content or a file path depending on the source.
     * Check meta['is_file_path'] to determine which.
     */
    public function getRawContent(): string
    {
        return $this->rawContent;
    }

    /**
     * Whether rawContent is a file path rather than inline content.
     * Large sources (OFAC) stream to disk to avoid memory exhaustion.
     */
    public function isFilePath(): bool
    {
        return ($this->meta['is_file_path'] ?? false) === true;
    }

    /**
     * Get the file size — works for both inline content and file paths.
     */
    public function getContentSize(): int
    {
        if ($this->isFilePath()) {
            return (int)filesize($this->rawContent);
        }
        return strlen($this->rawContent);
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * Whether the content has changed since the last sync.
     * Determined by comparing the content hash against the previous hash.
     */
    public function hasChanged(): bool
    {
        return $this->changed;
    }

    /**
     * Whether this fetch result represents a delta (incremental update)
     * rather than a full list replacement.
     */
    public function isDelta(): bool
    {
        return $this->delta;
    }

    /**
     * For delta sources, the changeset is built during parsing
     * since the delta file itself contains the add/update/delete actions.
     */
    public function getDeltaChangeset(): ?Changeset
    {
        return $this->deltaChangeset;
    }

    /**
     * Source-specific metadata from the fetch.
     * e.g. publish date, ETag, content-length, response headers
     */
    public function getMeta(): array
    {
        return $this->meta;
    }

    /**
     * Convenience factory for when the source hasn't changed.
     */
    public static function unchanged(string $hash): self
    {
        return new self('', $hash, false);
    }
}