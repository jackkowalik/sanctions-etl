<?php

namespace SanctionsEtl\Storage;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Diff\Changeset;

class JsonStore implements EntityStore
{
    private string $outputDir;
    private LoggerInterface $logger;
    private ?array $manifest = null;

    public function __construct(string $outputDir, LoggerInterface $logger)
    {
        $this->outputDir = rtrim($outputDir, '/');
        $this->logger = $logger;

        if (!is_dir($this->outputDir) && !mkdir($this->outputDir, 0755, true)) {
            throw new \RuntimeException("Failed to create output directory: {$this->outputDir}");
        }
    }

    public function getLastHash(string $sourceId): ?string
    {
        $manifest = $this->loadManifest();
        return $manifest['sources'][$sourceId]['last_file_hash'] ?? null;
    }

    public function getActiveHashes(string $sourceId): array
    {
        $hashes = [];
        foreach ($this->readEntities($sourceId) as $id => $row) {
            $hashes[$id] = $row['content_hash'];
        }
        return $hashes;
    }

    public function getLastEntityCount(string $sourceId): ?int
    {
        $manifest = $this->loadManifest();
        $count = $manifest['sources'][$sourceId]['entity_count'] ?? null;
        return $count !== null ? (int) $count : null;
    }

    public function apply(Changeset $changeset): array
    {
        $sourceId = $changeset->getSourceId();
        $entities = $this->readEntities($sourceId);

        $inserted = 0;
        $updated = 0;
        $delisted = 0;

        foreach ($changeset->getInserts() as $entity) {
            $row = $entity->toArray();
            $row['content_hash'] = $entity->getContentHash();
            $entities[$entity->getSourceEntityId()] = $row;
            $inserted++;
        }

        foreach ($changeset->getUpdates() as $entity) {
            $row = $entity->toArray();
            $row['content_hash'] = $entity->getContentHash();
            $entities[$entity->getSourceEntityId()] = $row;
            $updated++;
        }

        foreach ($changeset->getDelists() as $sourceEntityId) {
            if (isset($entities[$sourceEntityId])) {
                unset($entities[$sourceEntityId]);
                $delisted++;
            }
        }

        $this->writeEntities($sourceId, $entities);

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
        $manifest = $this->loadManifest();

        $manifest['sources'][$sourceId] = [
            'last_synced_at' => $meta['last_synced_at'],
            'last_file_hash' => $meta['file_hash'],
            'entity_count' => $meta['entity_count'],
            'last_changeset' => $meta['last_changeset'],
        ];
        $manifest['updated_at'] = date('c');

        $this->saveManifest($manifest);
    }

    public function logSync(string $sourceId, string $syncType, string $status, array $details = []): void
    {
        $line = json_encode([
            'timestamp' => date('c'),
            'source_id' => $sourceId,
            'sync_type' => $syncType,
            'status' => $status,
        ] + $details, JSON_UNESCAPED_SLASHES);

        file_put_contents($this->outputDir . '/sync_log.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return array<string, array> source_entity_id => decoded entity row
     */
    private function readEntities(string $sourceId): array
    {
        $file = $this->entitiesFile($sourceId);
        if (!is_file($file)) {
            return [];
        }

        $entities = [];
        $handle = fopen($file, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open entities file: {$file}");
        }

        $lineNo = 0;
        while (($line = fgets($handle)) !== false) {
            $lineNo++;
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $row = json_decode($line, true);
            if (!is_array($row) || !isset($row['source_entity_id'], $row['content_hash'])) {
                fclose($handle);
                throw new \RuntimeException("Corrupt entity record in {$file} at line {$lineNo}");
            }
            $entities[$row['source_entity_id']] = $row;
        }

        fclose($handle);
        return $entities;
    }

    private function writeEntities(string $sourceId, array $entities): void
    {
        $file = $this->entitiesFile($sourceId);
        $tmp = $file . '.tmp';

        $handle = fopen($tmp, 'w');
        if ($handle === false) {
            throw new \RuntimeException("Failed to open temp file for writing: {$tmp}");
        }

        foreach ($entities as $row) {
            fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        }

        fclose($handle);

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to replace entities file: {$file}");
        }
    }

    private function entitiesFile(string $sourceId): string
    {
        return $this->outputDir . '/' . $sourceId . '.jsonl';
    }

    private function loadManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $file = $this->outputDir . '/manifest.json';
        if (!is_file($file)) {
            return $this->manifest = ['sources' => [], 'updated_at' => null];
        }

        $decoded = json_decode(file_get_contents($file), true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Corrupt manifest: {$file}");
        }

        return $this->manifest = $decoded + ['sources' => []];
    }

    private function saveManifest(array $manifest): void
    {
        $this->manifest = $manifest;
        $file = $this->outputDir . '/manifest.json';
        $tmp = $file . '.tmp';

        file_put_contents($tmp, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to replace manifest: {$file}");
        }
    }
}
