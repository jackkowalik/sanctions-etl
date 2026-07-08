<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Config;
use Psr\Log\LoggerInterface;
use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\USCSLParser;

class USConsolidatedScreeningList implements SourceInterface
{
    private const URL = 'https://data.trade.gov/downloadable_consolidated_screening_list/v1/consolidated.csv';
    private const CACHE_TTL = 600;

    private LoggerInterface $logger;
    private string $activeSourceId;
    private ?string $downloadDir;

    /**
     * CSL source column value -> our internal source_id.
     * SDN is excluded -- we already ingest it from OFAC advanced XML.
     */
    public const SOURCE_MAP = [
        'Entity List (EL) - Bureau of Industry and Security' => 'us_bis_entity',
        'Denied Persons List (DPL) - Bureau of Industry and Security' => 'us_bis_denied',
        'Unverified List (UVL) - Bureau of Industry and Security' => 'us_bis_unverified',
        'Military End User (MEU) List - Bureau of Industry and Security' => 'us_bis_meu',
        'Non-SDN Chinese Military-Industrial Complex Companies List (CMIC) - Treasury Department' => 'us_cmic',
        'ITAR Debarred (DTC) - State Department' => 'us_itar_debarred',
        'Nonproliferation Sanctions (ISN) - State Department' => 'us_isn_nonprolif',
        'Sectoral Sanctions Identifications List (SSI) - Treasury Department' => 'us_ofac_ssi',
        'Non-SDN Menu-Based Sanctions List (NS-MBS List) - Treasury Department' => 'us_ofac_mbs',
        'Palestinian Legislative Council List (PLC) - Treasury Department' => 'us_ofac_plc',
        'Capta List (CAP) - Treasury Department' => 'us_ofac_capta'
    ];

    public const DISPLAY_NAMES = [
        'us_bis_entity' => 'US BIS Entity List',
        'us_bis_denied' => 'US BIS Denied Persons List',
        'us_bis_unverified' => 'US BIS Unverified List',
        'us_bis_meu' => 'US BIS Military End User List',
        'us_cmic' => 'US Non-SDN Chinese Military-Industrial Complex Companies List',
        'us_itar_debarred' => 'US ITAR Debarred Parties',
        'us_isn_nonprolif' => 'US Nonproliferation Sanctions',
        'us_ofac_ssi' => 'US OFAC Sectoral Sanctions Identifications List',
        'us_ofac_mbs' => 'US OFAC Non-SDN Menu-Based Sanctions List',
        'us_ofac_plc' => 'US OFAC Palestinian Legislative Council List',
        'us_ofac_capta' => 'US OFAC CAPTA List',
    ];

    public function __construct(LoggerInterface $logger, string $sourceId, ?string $downloadDir = null)
    {
        if (!in_array($sourceId, self::SOURCE_MAP, true)) {
            throw new \InvalidArgumentException("Unknown CSL source_id: {$sourceId}");
        }

        $this->logger = $logger;
        $this->activeSourceId = $sourceId;
        $this->downloadDir = $downloadDir;
    }

    public function getSourceId(): string
    {
        return $this->activeSourceId;
    }

    public function getDisplayName(): string
    {
        return self::DISPLAY_NAMES[$this->activeSourceId] ?? $this->activeSourceId;
    }

    public function getUrls(): array
    {
        return ['full' => self::URL];
    }

    public function getFormat(): string
    {
        return 'csv';
    }

    public function supportsDelta(): bool
    {
        return false;
    }

    public function getExpectedUpdateFrequency(): int
    {
        return 1440;
    }

    public function fetch(?string $lastHash = null): FetchResult
    {
        $destDir = $this->downloadDir ?? sys_get_temp_dir();

        // Reuse a recently downloaded CSL file from this sync run
        $cached = $this->findCachedFile($destDir);
        if ($cached !== null) {
            $hash = hash_file('sha256', $cached);

            if ($lastHash !== null && $hash === $lastHash) {
                return FetchResult::unchanged($hash);
            }

            $this->logger->info("Reusing cached CSL file", [
                'source_id' => $this->activeSourceId,
                'file' => $cached,
            ]);

            return new FetchResult(
                rawContent: $cached,
                hash: $hash,
                changed: true,
                delta: false,
                deltaChangeset: null,
                meta: [
                    'file_size' => filesize($cached),
                    'url' => self::URL,
                    'fetched_at' => date('Y-m-d H:i:s'),
                    'is_file_path' => true,
                    'cached' => true,
                ]
            );
        }

        $destFile = $destDir . '/us_csl_' . date('Ymd_His') . '.csv';

        $this->logger->info("Fetching US Consolidated Screening List", [
            'source_id' => $this->activeSourceId,
            'url' => self::URL
        ]);

        $fp = fopen($destFile, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open file: {$destFile}");
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::URL,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT => Config::USER_AGENT,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($httpCode !== 200) {
            @unlink($destFile);
            throw new \RuntimeException("Failed to fetch CSL: HTTP {$httpCode} - {$error}");
        }

        $fileSize = filesize($destFile);
        if ($fileSize === 0) {
            @unlink($destFile);
            throw new \RuntimeException("CSL returned empty response");
        }

        $hash = hash_file('sha256', $destFile);

        if ($lastHash !== null && $hash === $lastHash) {
            // keep the shared file: sibling sub-lists reuse it via findCachedFile
            return FetchResult::unchanged($hash);
        }

        $this->logger->info("CSL data fetched", [
            'source_id' => $this->activeSourceId,
            'http_code' => $httpCode,
            'file_size' => $fileSize,
            'hash' => $hash,
        ]);

        return new FetchResult(
            rawContent: $destFile,
            hash: $hash,
            changed: true,
            delta: false,
            deltaChangeset: null,
            meta: [
                'http_code' => $httpCode,
                'file_size' => $fileSize,
                'url' => self::URL,
                'fetched_at' => date('Y-m-d H:i:s'),
                'is_file_path' => true,
                'cached' => false,
            ]
        );
    }

    /**
     * Find a CSL file downloaded within the cache TTL window.
     * Prevents re-downloading the same 16MB CSV for each sub-list
     * when SyncAll processes them sequentially.
     */
    private function findCachedFile(string $dir): ?string
    {
        $pattern = $dir . '/us_csl_*.csv';
        $files = glob($pattern);

        if (empty($files)) return null;

        sort($files);
        $latest = end($files);

        $mtime = filemtime($latest);
        if ($mtime === false) return null;

        if ((time() - $mtime) > self::CACHE_TTL) return null;

        return $latest;
    }

    public function getParser(): ParserInterface
    {
        return new USCSLParser($this->logger, $this->activeSourceId);
    }

    /**
     * Factory to create source instances for all CSL sub-lists.
     *
     * @return self[]
     */
    public static function all(LoggerInterface $logger, ?string $downloadDir = null): array
    {
        $sources = [];
        foreach (self::SOURCE_MAP as $csvSource => $sourceId) {
            $sources[] = new self($logger, $sourceId, $downloadDir);
        }
        return $sources;
    }
}