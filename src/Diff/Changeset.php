<?php

namespace SanctionsEtl\Diff;

use SanctionsEtl\Data\SanctionedEntity;

class Changeset
{
    /** @var SanctionedEntity[] */
    private array $inserts = [];

    /** @var SanctionedEntity[] */
    private array $updates = [];

    /** @var string[] source_entity_ids to delist */
    private array $delists = [];

    private string $sourceId;

    public function __construct(string $sourceId)
    {
        $this->sourceId = $sourceId;
    }

    public function addInsert(SanctionedEntity $entity): void
    {
        $this->inserts[] = $entity;
    }

    public function addUpdate(SanctionedEntity $entity): void
    {
        $this->updates[] = $entity;
    }

    public function addDelist(string $sourceEntityId): void
    {
        $this->delists[] = $sourceEntityId;
    }

    /** @return SanctionedEntity[] */
    public function getInserts(): array { return $this->inserts; }

    /** @return SanctionedEntity[] */
    public function getUpdates(): array { return $this->updates; }

    /** @return string[] */
    public function getDelists(): array { return $this->delists; }

    public function getSourceId(): string { return $this->sourceId; }

    public function isEmpty(): bool
    {
        return empty($this->inserts)
            && empty($this->updates)
            && empty($this->delists);
    }

    public function getSummary(): array
    {
        return [
            'source_id' => $this->sourceId,
            'inserts' => count($this->inserts),
            'updates' => count($this->updates),
            'delists' => count($this->delists),
            'total_changes' => count($this->inserts) + count($this->updates) + count($this->delists),
        ];
    }
}