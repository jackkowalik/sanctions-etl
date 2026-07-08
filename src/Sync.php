<?php

namespace SanctionsEtl;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Diff\ChangesetBuilder;
use SanctionsEtl\Download\SourceInterface;
use SanctionsEtl\Download\UNSecurityCouncil;
use SanctionsEtl\Download\EUConsolidated;
use SanctionsEtl\Download\UKSanctions;
use SanctionsEtl\Download\CanadaSEMA;
use SanctionsEtl\Download\SwissSECO;
use SanctionsEtl\Download\UKHMTreasury;
use SanctionsEtl\Storage\EntityStore;

/**
 * Runs the fetch > parse > diff > load pipeline across all registered
 * sources. The storage backend is injected so the same loop drives the
 * JSONL and MySQL modes.
 */
class Sync
{
    private Config $config;
    private LoggerInterface $logger;
    private EntityStore $store;
    private ChangesetBuilder $differ;

    /** @var SourceInterface[] */
    private array $sources = [];

    public function __construct(Config $config, LoggerInterface $logger, EntityStore $store)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->store = $store;
        $this->differ = new ChangesetBuilder($logger);

        if (!is_dir($config->downloadDir())) {
            mkdir($config->downloadDir(), 0755, true);
        }

        $this->registerSources();
    }

    // new sources get registered here, one line each
    private function registerSources(): void
    {
        $logger = $this->logger;
        $dir = $this->config->downloadDir();

        $this->sources = [
            new UNSecurityCouncil($logger, $dir),
            new EUConsolidated($logger, $dir),
            new UKSanctions($logger, $dir),
            new CanadaSEMA($logger, $dir),
            new SwissSECO($logger, $dir),
            new UKHMTreasury($logger, $dir),
        ];
    }

    /** @return SourceInterface[] */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * Sync every registered source, or a single one by id.
     *
     * @param string|null $onlySource source id to sync, null for all
     * @return int process exit code, nonzero if any source failed
     */
    public function execute(?string $onlySource = null): int
    {
        $sources = $this->sources;

        if ($onlySource !== null) {
            $sources = array_filter($sources, fn($s) => $s->getSourceId() === $onlySource);
            if (empty($sources)) {
                echo "Unknown source: {$onlySource}\n";
                echo "Available sources:\n";
                foreach ($this->sources as $s) {
                    echo "  {$s->getSourceId()} - {$s->getDisplayName()}\n";
                }
                return 1;
            }
        }

        $this->logger->info('Sanctions sync started', [
            'source_count' => count($sources),
            'sources' => array_map(fn($s) => $s->getSourceId(), array_values($sources)),
        ]);

        $totalStart = microtime(true);
        $results = [];
        foreach ($sources as $source) {
            $results[$source->getSourceId()] = $this->syncSource($source);
        }
        $totalMs = (int) round((microtime(true) - $totalStart) * 1000);

        $this->logger->info('Sanctions sync complete', ['duration_ms' => $totalMs]);

        $this->printSummary($results, $totalMs);

        if (!$this->config->keepDownloads()) {
            $this->cleanupDownloads();
        }

        // nonzero exit if anything failed, so cron can alert on it
        foreach ($results as $result) {
            if ($result['status'] === 'error') {
                return 1;
            }
        }
        return 0;
    }

    private function syncSource(SourceInterface $source): array
    {
        $sourceId = $source->getSourceId();
        $start = microtime(true);

        echo "Syncing {$source->getDisplayName()}...\n";

        try {
            $lastHash = $this->store->getLastHash($sourceId);

            $fetchStart = microtime(true);
            $fetch = $source->fetch($lastHash);
            $fetchMs = (int) round((microtime(true) - $fetchStart) * 1000);

            // content hash matches the previous sync, nothing to do
            if (!$fetch->hasChanged()) {
                $elapsed = (int) round((microtime(true) - $start) * 1000);
                echo "  Unchanged (skipped) [{$fetchMs}ms]\n\n";
                $this->store->logSync($sourceId, 'full', 'skipped', [
                    'file_hash' => $fetch->getHash(),
                    'duration_ms' => $elapsed,
                ]);
                return ['status' => 'skipped', 'duration_ms' => $elapsed];
            }

            echo "  Fetched [{$fetchMs}ms, " . number_format($fetch->getContentSize()) . " bytes]\n";

            $parseStart = microtime(true);
            $entities = $source->getParser()->parse($fetch->getRawContent(), $sourceId);
            $parseMs = (int) round((microtime(true) - $parseStart) * 1000);

            echo "  Parsed " . count($entities) . " entities [{$parseMs}ms]\n";

            // zero entities means the parser broke or the source changed its
            // format, never a genuinely empty list. Bailing here keeps the
            // diff stage from delisting the entire source.
            if (empty($entities)) {
                throw new \RuntimeException("Parser returned zero entities for {$sourceId}");
            }

            $diffStart = microtime(true);
            $existing = $this->store->getActiveHashes($sourceId);
            $changeset = $this->differ->build($sourceId, $entities, $existing);
            $diffMs = (int) round((microtime(true) - $diffStart) * 1000);

            $summary = $changeset->getSummary();
            echo "  Changeset: +{$summary['inserts']} ~{$summary['updates']} -{$summary['delists']} [{$diffMs}ms]\n";

            // a truncated or half-parsed file shows up as a large batch of
            // delists, not as zero entities. Refuse to apply anything that
            // would delist more than half the source in one run because its
            // more likely than not a suspicious change.
            if (count($existing) > 0 && $summary['delists'] > 0.5 * count($existing)) {
                throw new \RuntimeException(sprintf(
                    "Refusing changeset for %s: %d delists against %d existing entities. "
                    . "If this is a genuine list revision, delete out/%s.jsonl and re-sync.",
                    $sourceId, $summary['delists'], count($existing), $sourceId
                ));
            }

            $loadResult = ['inserted' => 0, 'updated' => 0, 'delisted' => 0, 'errors' => 0];
            if (!$changeset->isEmpty()) {
                $loadStart = microtime(true);
                $loadResult = $this->store->apply($changeset);
                $loadMs = (int) round((microtime(true) - $loadStart) * 1000);
                echo "  Loaded [{$loadMs}ms]\n";
            } else {
                echo "  No changes to apply\n";
            }

            $this->store->updateSourceMeta($sourceId, [
                'last_synced_at' => date('Y-m-d H:i:s'),
                'file_hash' => $fetch->getHash(),
                'entity_count' => count($entities),
                'last_changeset' => $summary,
            ]);

            $elapsed = (int) round((microtime(true) - $start) * 1000);

            $this->store->logSync($sourceId, 'full', 'success', [
                'file_hash' => $fetch->getHash(),
                'entities_parsed' => count($entities),
                'inserts' => $loadResult['inserted'],
                'updates' => $loadResult['updated'],
                'delists' => $loadResult['delisted'],
                'duration_ms' => $elapsed,
            ]);

            echo "  Done [{$elapsed}ms]\n\n";

            return [
                'status' => 'success',
                'entities_parsed' => count($entities),
                'inserts' => $loadResult['inserted'],
                'updates' => $loadResult['updated'],
                'delists' => $loadResult['delisted'],
                'duration_ms' => $elapsed,
            ];

        } catch (\Exception $e) {
            $elapsed = (int) round((microtime(true) - $start) * 1000);

            echo "  FAILED: {$e->getMessage()}\n\n";

            $this->logger->error("Failed to sync {$sourceId}", [
                'error' => $e->getMessage(),
            ]);

            // a failed source is recorded and skipped, the rest of the run continues
            $this->store->logSync($sourceId, 'full', 'error', [
                'duration_ms' => $elapsed,
                'error_message' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage(), 'duration_ms' => $elapsed];
        }
    }

    private function printSummary(array $results, int $totalMs): void
    {
        echo str_repeat('=', 80) . "\n";
        echo "SYNC SUMMARY\n";
        echo str_repeat('=', 80) . "\n";

        $totals = ['entities' => 0, 'inserts' => 0, 'updates' => 0, 'delists' => 0];

        foreach ($results as $sourceId => $result) {
            $line = sprintf("  %-30s %s", $sourceId, strtoupper($result['status']));

            if ($result['status'] === 'success') {
                $totals['entities'] += $result['entities_parsed'];
                $totals['inserts'] += $result['inserts'];
                $totals['updates'] += $result['updates'];
                $totals['delists'] += $result['delists'];
                $line .= sprintf(
                    "  +%-5d ~%-5d -%-5d  [%dms]",
                    $result['inserts'], $result['updates'], $result['delists'], $result['duration_ms']
                );
            } elseif ($result['status'] === 'skipped') {
                $line .= sprintf("  (unchanged)  [%dms]", $result['duration_ms']);
            } else {
                $line .= "  " . mb_substr($result['error'], 0, 50);
            }

            echo $line . "\n";
        }

        echo str_repeat('-', 80) . "\n";
        echo sprintf(
            "  Total: %d entities, +%d inserts, ~%d updates, -%d delists\n",
            $totals['entities'], $totals['inserts'], $totals['updates'], $totals['delists']
        );
        echo sprintf("  Duration: %dms (%.1fs)\n", $totalMs, $totalMs / 1000);
        echo str_repeat('=', 80) . "\n";
    }

    // raw downloads can be large (SECO is around 40MB), so they are removed
    // after the run unless KEEP_DOWNLOADS is set for debugging
    private function cleanupDownloads(): void
    {
        $deleted = 0;
        foreach ($this->sources as $source) {
            $pattern = $this->config->downloadDir() . '/' . $source->getSourceId() . '_*';
            foreach (glob($pattern) as $file) {
                if (@unlink($file)) {
                    $deleted++;
                }
            }
        }
        if ($deleted > 0) {
            $this->logger->info("Cleaned up downloaded files", ['deleted' => $deleted]);
        }
    }
}
