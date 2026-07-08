<?php

namespace SanctionsEtl\Storage;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;
use SanctionsEtl\Diff\Changeset;

class MysqlStore implements EntityStore
{
    private \PDO $pdo;
    private LoggerInterface $logger;

    public function __construct(\PDO $pdo, LoggerInterface $logger)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    public function getLastHash(string $sourceId): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT last_file_hash FROM sanctions_sources WHERE source_id = :source_id"
        );
        $stmt->execute([':source_id' => $sourceId]);
        $row = $stmt->fetch();
        return $row === false ? null : ($row['last_file_hash'] ?? null);
    }

    public function getActiveHashes(string $sourceId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT source_entity_id, content_hash FROM sanctions_entities
             WHERE source_id = :source_id AND delisted_at IS NULL"
        );
        $stmt->execute([':source_id' => $sourceId]);

        $hashes = [];
        foreach ($stmt as $row) {
            $hashes[$row['source_entity_id']] = $row['content_hash'];
        }
        return $hashes;
    }

    public function getLastEntityCount(string $sourceId): ?int
    {
        $stmt = $this->pdo->prepare(
            "SELECT entity_count FROM sanctions_sources WHERE source_id = :source_id"
        );
        $stmt->execute([':source_id' => $sourceId]);
        $row = $stmt->fetch();
        return $row === false ? null : (int) $row['entity_count'];
    }

    public function apply(Changeset $changeset): array
    {
        $sourceId = $changeset->getSourceId();

        $inserted = 0;
        $updated = 0;
        $delisted = 0;

        $this->pdo->beginTransaction();

        try {
            foreach ($changeset->getInserts() as $entity) {
                $this->upsertEntity($sourceId, $entity);
                $inserted++;
            }

            foreach ($changeset->getUpdates() as $entity) {
                $this->upsertEntity($sourceId, $entity);
                $updated++;
            }

            foreach ($changeset->getDelists() as $sourceEntityId) {
                $delisted += $this->delist($sourceId, $sourceEntityId);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new \RuntimeException(
                "Failed to apply changeset for {$sourceId}: {$e->getMessage()}", 0, $e
            );
        }

        $result = [
            'inserted' => $inserted,
            'updated' => $updated,
            'delisted' => $delisted,
            'errors' => 0,
        ];

        $this->logger->info("Changeset applied", ['source_id' => $sourceId] + $result);

        return $result;
    }

    public function updateSourceMeta(string $sourceId, array $meta): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO sanctions_sources
                (source_id, last_synced_at, last_file_hash, entity_count, last_changeset, status)
            VALUES
                (:source_id, :last_synced_at, :last_file_hash, :entity_count, :last_changeset, 'active')
            ON DUPLICATE KEY UPDATE
                last_synced_at = VALUES(last_synced_at),
                last_file_hash = VALUES(last_file_hash),
                entity_count = VALUES(entity_count),
                last_changeset = VALUES(last_changeset),
                status = 'active'
        ");

        $stmt->execute([
            ':source_id' => $sourceId,
            ':last_synced_at' => $meta['last_synced_at'],
            ':last_file_hash' => $meta['file_hash'],
            ':entity_count' => $meta['entity_count'],
            ':last_changeset' => json_encode($meta['last_changeset'], JSON_UNESCAPED_SLASHES),
        ]);
    }

    public function logSync(string $sourceId, string $syncType, string $status, array $details = []): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO sanctions_sync_log
                (source_id, sync_type, status, file_hash, entities_parsed,
                 inserts, updates, delists, duration_ms, error_message)
            VALUES
                (:source_id, :sync_type, :status, :file_hash, :entities_parsed,
                 :inserts, :updates, :delists, :duration_ms, :error_message)
        ");

        $stmt->execute([
            ':source_id' => $sourceId,
            ':sync_type' => $syncType,
            ':status' => $status,
            ':file_hash' => $details['file_hash'] ?? null,
            ':entities_parsed' => $details['entities_parsed'] ?? null,
            ':inserts' => $details['inserts'] ?? null,
            ':updates' => $details['updates'] ?? null,
            ':delists' => $details['delists'] ?? null,
            ':duration_ms' => $details['duration_ms'] ?? null,
            ':error_message' => $details['error_message'] ?? null,
        ]);
    }

    /**
     * Inserts and updates share this path. A previously delisted entity
     * that reappears upstream arrives as an insert; the upsert restores
     * it in place by clearing delisted_at.
     */
    private function upsertEntity(string $sourceId, SanctionedEntity $entity): void
    {
        $row = $entity->toArray();

        $stmt = $this->pdo->prepare("
            INSERT INTO sanctions_entities
                (source_id, source_entity_id, entity_type, primary_name, dates,
                 nationalities, programs, listed_date, remarks, content_hash, delisted_at)
            VALUES
                (:source_id, :source_entity_id, :entity_type, :primary_name, :dates,
                 :nationalities, :programs, :listed_date, :remarks, :content_hash, NULL)
            ON DUPLICATE KEY UPDATE
                entity_type = VALUES(entity_type),
                primary_name = VALUES(primary_name),
                dates = VALUES(dates),
                nationalities = VALUES(nationalities),
                programs = VALUES(programs),
                listed_date = VALUES(listed_date),
                remarks = VALUES(remarks),
                content_hash = VALUES(content_hash),
                delisted_at = NULL
        ");

        $stmt->execute([
            ':source_id' => $sourceId,
            ':source_entity_id' => $row['source_entity_id'],
            ':entity_type' => $row['entity_type'],
            ':primary_name' => mb_substr($row['primary_name'], 0, 512),
            ':dates' => json_encode($row['dates'], JSON_UNESCAPED_UNICODE),
            ':nationalities' => json_encode($row['nationalities'], JSON_UNESCAPED_UNICODE),
            ':programs' => json_encode($row['programs'], JSON_UNESCAPED_UNICODE),
            ':listed_date' => $row['listed_date'] !== null ? mb_substr($row['listed_date'], 0, 32) : null,
            ':remarks' => $row['remarks'],
            ':content_hash' => $entity->getContentHash(),
        ]);

        // lastInsertId is unreliable after ON DUPLICATE KEY UPDATE,
        // so the id is looked up directly
        $entityId = $this->getEntityId($sourceId, $row['source_entity_id']);

        $this->replaceChildren($entityId, $row);
    }

    private function getEntityId(string $sourceId, string $sourceEntityId): int
    {
        $stmt = $this->pdo->prepare("
            SELECT id FROM sanctions_entities
            WHERE source_id = :source_id AND source_entity_id = :source_entity_id
        ");
        $stmt->execute([
            ':source_id' => $sourceId,
            ':source_entity_id' => $sourceEntityId,
        ]);

        $row = $stmt->fetch();
        if ($row === false) {
            throw new \RuntimeException(
                "Entity vanished mid-apply: {$sourceId}/{$sourceEntityId}"
            );
        }
        return (int) $row['id'];
    }

    private function replaceChildren(int $entityId, array $row): void
    {
        foreach (['sanctions_aliases', 'sanctions_identifiers', 'sanctions_addresses'] as $table) {
            $stmt = $this->pdo->prepare("DELETE FROM {$table} WHERE entity_id = :entity_id");
            $stmt->execute([':entity_id' => $entityId]);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO sanctions_aliases (entity_id, name, alias_type, low_quality)
            VALUES (:entity_id, :name, :alias_type, :low_quality)
        ");
        foreach ($row['aliases'] as $alias) {
            $name = trim((string) ($alias['name'] ?? ''));
            if ($name === '') continue;

            $stmt->execute([
                ':entity_id' => $entityId,
                ':name' => mb_substr($name, 0, 512),
                ':alias_type' => mb_substr((string) ($alias['type'] ?? 'aka'), 0, 32),
                ':low_quality' => !empty($alias['low_quality']) ? 1 : 0,
            ]);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO sanctions_identifiers (entity_id, id_type, id_value, country, valid)
            VALUES (:entity_id, :id_type, :id_value, :country, :valid)
        ");
        foreach ($row['identifiers'] as $identifier) {
            $value = trim((string) ($identifier['value'] ?? ''));
            if ($value === '') continue;

            $stmt->execute([
                ':entity_id' => $entityId,
                ':id_type' => mb_substr((string) ($identifier['type'] ?? 'unknown'), 0, 64),
                ':id_value' => mb_substr($value, 0, 255),
                ':country' => mb_substr((string) ($identifier['country'] ?? ''), 0, 64),
                ':valid' => array_key_exists('valid', $identifier)
                    ? (!empty($identifier['valid']) ? 1 : 0)
                    : 1,
            ]);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO sanctions_addresses (entity_id, full_address, city, region, postal_code, country)
            VALUES (:entity_id, :full_address, :city, :region, :postal_code, :country)
        ");
        foreach ($row['addresses'] as $address) {
            $full = trim((string) ($address['full'] ?? ''));
            $country = trim((string) ($address['country'] ?? ''));
            if ($full === '' && $country === '') continue;

            $stmt->execute([
                ':entity_id' => $entityId,
                ':full_address' => $full !== '' ? mb_substr($full, 0, 750) : null,
                ':city' => ($address['city'] ?? '') !== '' ? mb_substr((string) $address['city'], 0, 128) : null,
                ':region' => ($address['region'] ?? '') !== '' ? mb_substr((string) $address['region'], 0, 128) : null,
                ':postal_code' => ($address['postal'] ?? '') !== '' ? mb_substr((string) $address['postal'], 0, 32) : null,
                ':country' => $country !== '' ? mb_substr($country, 0, 64) : null,
            ]);
        }
    }

    private function delist(string $sourceId, string $sourceEntityId): int
    {
        $stmt = $this->pdo->prepare("
            UPDATE sanctions_entities
            SET delisted_at = NOW()
            WHERE source_id = :source_id
              AND source_entity_id = :source_entity_id
              AND delisted_at IS NULL
        ");
        $stmt->execute([
            ':source_id' => $sourceId,
            ':source_entity_id' => $sourceEntityId,
        ]);

        return $stmt->rowCount();
    }
}
