<?php

namespace SanctionsEtl\Storage;

use SanctionsEtl\Diff\Changeset;

interface EntityStore
{
    /**
     * Last file hash recorded for a source, used to short-circuit
     * a sync when the downloaded content is unchanged.
     */
    public function getLastHash(string $sourceId): ?string;

    /**
     * Current state of a source for diffing.
     *
     * @return array<string, string> source_entity_id => content_hash
     */
    public function getActiveHashes(string $sourceId): array;

    /**
     * Apply a changeset to the backend.
     *
     * @return array{inserted: int, updated: int, delisted: int, errors: int}
     */
    public function apply(Changeset $changeset): array;

    /**
     * Record source-level metadata after a sync run.
     *
     * @param array{last_synced_at: string, file_hash: string, entity_count: int, last_changeset: array} $meta
     */
    public function updateSourceMeta(string $sourceId, array $meta): void;

    /**
     * Append a sync run to the audit log.
     */
    public function logSync(string $sourceId, string $syncType, string $status, array $details = []): void;
}
