<?php

namespace SanctionsEtl\Download;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Config;
use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\UKHMTCSVParser;

class UKHMTreasury implements SourceInterface
{
    private const URL = 'https://ofsistorage.blob.core.windows.net/publishlive/2022format/ConList.csv';
    private const SOURCE_ID = 'gb_hmt';

    private LoggerInterface $logger;
    private ?string $downloadDir;

    public function __construct(LoggerInterface $logger, ?string $downloadDir = null)
    {
        $this->logger = $logger;
        $this->downloadDir = $downloadDir;
    }

    public function getSourceId(): string { return self::SOURCE_ID; }
    public function getDisplayName(): string { return 'UK HM Treasury/OFSI Consolidated List'; }
    public function getUrls(): array { return ['full' => self::URL]; }
    public function getFormat(): string { return 'csv'; }
    public function supportsDelta(): bool { return false; }
    public function getExpectedUpdateFrequency(): int { return 1440; }

    public function fetch(?string $lastHash = null): FetchResult
    {
        $destDir = $this->downloadDir ?? sys_get_temp_dir();
        $destFile = $destDir . '/' . self::SOURCE_ID . '_' . date('Ymd_His') . '.csv';

        $this->logger->info("Fetching UK HMT/OFSI consolidated list", ['url' => self::URL]);

        $fp = fopen($destFile, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open file: {$destFile}");
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::URL,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT => Config::USER_AGENT,
        ]);

        $ok = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $errno !== 0 || $httpCode !== 200) {
            @unlink($destFile);
            throw new \RuntimeException("Failed to fetch UK HMT list: HTTP {$httpCode}, curl errno {$errno} - {$error}");
        }

        $fileSize = filesize($destFile);
        if ($fileSize === 0) {
            @unlink($destFile);
            throw new \RuntimeException("UK HMT returned empty response");
        }

        $hash = hash_file('sha256', $destFile);

        if ($lastHash !== null && $hash === $lastHash) {
            @unlink($destFile);
            return FetchResult::unchanged($hash);
        }

        $this->logger->info("UK HMT data fetched", [
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
            ]
        );
    }

    public function getParser(): ParserInterface
    {
        return new UKHMTCSVParser($this->logger);
    }
}
