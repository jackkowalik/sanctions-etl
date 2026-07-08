<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Parse\ParserInterface;

interface SourceInterface
{
    /**
     * Unique identifier for this source (e.g. 'ofac_sdn', 'un_consolidated', 'eu_consolidated')
     * Used as the foreign key in the entities table and the source metadata table.
     */
    public function getSourceId(): string;

    /**
     * Human-readable name for logging and monitoring.
     */
    public function getDisplayName(): string;

    /**
     * Download URLs for this source.
     * Most sources have a single URL. Some (like OFAC) have separate full + delta URLs.
     *
     * @return array{full: string, delta?: string}
     */
    public function getUrls(): array;

    /**
     * Raw format of the downloaded data: 'xml', 'csv', 'json', 'html'
     */
    public function getFormat(): string;

    /**
     * Whether this source publishes incremental delta files with explicit
     * add/update/delete actions. If false, the pipeline diffs the full
     * list against the current DB state to compute the changeset.
     */
    public function supportsDelta(): bool;

    /**
     * Expected update frequency in minutes. Used for monitoring —
     * if a source hasn't changed in longer than this, something may be wrong.
     * OFAC: ~1440 (daily), UN: ~10080 (weekly), etc.
     */
    public function getExpectedUpdateFrequency(): int;

    /**
     * Fetch the latest data from the source.
     * Handles HTTP requests, compression, pagination if needed.
     * Returns a FetchResult containing raw content, hash, and delta metadata.
     *
     * @param string|null $lastHash Hash from the previous sync. If the source
     *                              supports ETags or content hashing, this is
     *                              used to short-circuit when nothing changed.
     */
    public function fetch(?string $lastHash = null): FetchResult;

    /**
     * Returns the parser that knows how to normalize this source's
     * raw format into SanctionedEntity objects.
     */
    public function getParser(): ParserInterface;
}