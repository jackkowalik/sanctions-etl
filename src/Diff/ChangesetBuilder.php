<?php

namespace SanctionsEtl\Diff;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;

class ChangesetBuilder
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Diff parsed entities against the current known state for a source.
     *
     * @param string $sourceId
     * @param SanctionedEntity[] $parsedEntities
     * @param array<string, string> $existingHashes source_entity_id => content_hash
     *        for all active entities, as supplied by the storage backend
     * @return Changeset
     */
    public function build(string $sourceId, array $parsedEntities, array $existingHashes): Changeset
    {
        $changeset = new Changeset($sourceId);

        $this->logger->info("Building changeset", [
            'source_id' => $sourceId,
            'parsed_count' => count($parsedEntities),
            'existing_count' => count($existingHashes),
        ]);

        $seenIds = [];

        foreach ($parsedEntities as $entity) {
            $entityId = $entity->getSourceEntityId();
            $contentHash = $entity->getContentHash();
            $seenIds[$entityId] = true;

            if (!isset($existingHashes[$entityId])) {
                $changeset->addInsert($entity);
            } elseif ($existingHashes[$entityId] !== $contentHash) {
                $changeset->addUpdate($entity);
            }
        }

        foreach ($existingHashes as $entityId => $hash) {
            if (!isset($seenIds[$entityId])) {
                $changeset->addDelist($entityId);
            }
        }

        $this->logger->info("Changeset built", $changeset->getSummary());

        return $changeset;
    }
}
