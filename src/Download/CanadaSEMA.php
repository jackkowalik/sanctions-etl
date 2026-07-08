<?php

namespace SanctionsEtl\Download;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Config;
use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\CanadaSEMAXMLParser;

class CanadaSEMA implements SourceInterface
{
    private const URL = 'https://www.international.gc.ca/world-monde/assets/office_docs/international_relations-relations_internationales/sanctions/sema-lmes.xml';
    private const SOURCE_ID = 'ca_sema';

    private LoggerInterface $logger;
    private ?string $downloadDir;

    public function __construct(LoggerInterface $logger, ?string $downloadDir = null)
    {
        $this->logger = $logger;
        $this->downloadDir = $downloadDir;
    }

    public function getSourceId(): string { return self::SOURCE_ID; }
    public function getDisplayName(): string { return 'Canada SEMA Consolidated Sanctions List'; }
    public function getUrls(): array { return ['full' => self::URL]; }
    public function getFormat(): string { return 'xml'; }
    public function supportsDelta(): bool { return false; }
    public function getExpectedUpdateFrequency(): int { return 10080; }

    public function fetch(?string $lastHash = null): FetchResult
    {
        $destDir = $this->downloadDir ?? sys_get_temp_dir();
        $destFile = $destDir . '/' . self::SOURCE_ID . '_' . date('Ymd_His') . '.xml';

        $this->logger->info("Fetching Canada SEMA list", ['url' => self::URL]);

        $fp = fopen($destFile, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open file: {$destFile}");
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => self::URL,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
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
            throw new \RuntimeException("Failed to fetch Canada list: HTTP {$httpCode} - {$error}");
        }

        $fileSize = filesize($destFile);
        if ($fileSize === 0) {
            @unlink($destFile);
            throw new \RuntimeException("Canada returned empty response");
        }

        $hash = hash_file('sha256', $destFile);

        if ($lastHash !== null && $hash === $lastHash) {
            @unlink($destFile);
            return FetchResult::unchanged($hash);
        }

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
        return new CanadaSEMAXMLParser($this->logger);
    }
}
