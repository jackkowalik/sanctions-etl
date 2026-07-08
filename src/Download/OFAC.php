<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Config;
use Psr\Log\LoggerInterface;
use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\OFACAdvancedXMLParser;

class OFAC implements SourceInterface
{
    private const SDN_URL = 'https://www.treasury.gov/ofac/downloads/sanctions/1.0/sdn_advanced.xml';
    private const CONSOLIDATED_URL = 'https://www.treasury.gov/ofac/downloads/sanctions/1.0/cons_advanced.xml';

    private const SOURCE_ID_SDN = 'ofac_sdn';
    private const SOURCE_ID_CONSOLIDATED = 'ofac_consolidated';

    private LoggerInterface $logger;
    private string $activeList;
    private ?string $downloadDir;

    public function __construct(LoggerInterface $logger, string $list = 'sdn', ?string $downloadDir = null)
    {
        $this->logger = $logger;
        $this->activeList = $list;
        $this->downloadDir = $downloadDir;
    }

    public function getSourceId(): string
    {
        return $this->activeList === 'sdn'
            ? self::SOURCE_ID_SDN
            : self::SOURCE_ID_CONSOLIDATED;
    }

    public function getDisplayName(): string
    {
        return $this->activeList === 'sdn'
            ? 'OFAC SDN List'
            : 'OFAC Consolidated Non-SDN List';
    }

    public function getUrls(): array
    {
        $url = $this->activeList === 'sdn'
            ? self::SDN_URL
            : self::CONSOLIDATED_URL;

        return ['full' => $url];
    }

    public function getFormat(): string
    {
        return 'xml';
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
        $urls = $this->getUrls();
        $url = $urls['full'];
        $destDir = $this->downloadDir ?? sys_get_temp_dir();
        $destFile = $destDir . '/' . $this->getSourceId() . '_' . date('Ymd_His') . '.xml';

        $this->logger->info("Fetching OFAC data", [
            'source_id' => $this->getSourceId(),
            'url' => $url,
            'dest_file' => $destFile
        ]);

        $fp = fopen($destFile, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open file for writing: {$destFile}");
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT => Config::USER_AGENT,
            CURLOPT_HTTPHEADER => [
                'Accept: application/xml',
            ],
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($httpCode !== 200) {
            @unlink($destFile);
            throw new \RuntimeException(
                "Failed to fetch OFAC {$this->getSourceId()}: HTTP {$httpCode} - {$error}"
            );
        }

        $fileSize = filesize($destFile);
        if ($fileSize === 0) {
            @unlink($destFile);
            throw new \RuntimeException(
                "OFAC returned empty response for {$this->getSourceId()}"
            );
        }

        $hash = hash_file('sha256', $destFile);

        if ($lastHash !== null && $hash === $lastHash) {
            @unlink($destFile);
            $this->logger->info("OFAC content unchanged (hash match)", [
                'source_id' => $this->getSourceId(),
                'hash' => $hash
            ]);
            return FetchResult::unchanged($hash);
        }

        $this->logger->info("OFAC data fetched", [
            'source_id' => $this->getSourceId(),
            'http_code' => $httpCode,
            'file_size' => $fileSize,
            'hash' => $hash,
            'dest_file' => $destFile
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
                'url' => $url,
                'fetched_at' => date('Y-m-d H:i:s'),
                'is_file_path' => true,
            ]
        );
    }

    public function getParser(): ParserInterface
    {
        return new OFACAdvancedXMLParser($this->logger);
    }

    /**
     * Factory to create both OFAC source instances.
     * Used by SyncAll to register both the SDN and consolidated lists.
     *
     * @return self[]
     */
    public static function all(LoggerInterface $logger, ?string $downloadDir = null): array
    {
        return [
            new self($logger, 'sdn', $downloadDir),
            new self($logger, 'consolidated', $downloadDir),
        ];
    }
}