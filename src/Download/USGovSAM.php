<?php

namespace SanctionsEtl\Download;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Config;
use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\USGovSAMParser;

class USGovSAM implements SourceInterface
{
    private const URL = 'https://api.sam.gov/data-services/v1/extracts?api_key=%s&fileType=EXCLUSION';
    private const SOURCE_ID = 'us_sam_exclusions';

    private LoggerInterface $logger;
    private string $apiKey;
    private ?string $downloadDir;

    public function __construct(LoggerInterface $logger, string $apiKey, ?string $downloadDir = null)
    {
        $this->logger = $logger;
        $this->apiKey = $apiKey;
        $this->downloadDir = $downloadDir;
    }

    public function getSourceId(): string { return self::SOURCE_ID; }
    public function getDisplayName(): string { return 'US SAM.gov Procurement Exclusions'; }
    public function getUrls(): array { return ['full' => 'https://api.sam.gov/data-services/v1/extracts']; }
    public function getFormat(): string { return 'csv'; }
    public function supportsDelta(): bool { return false; }
    public function getExpectedUpdateFrequency(): int { return 1440; }

    public function fetch(?string $lastHash = null): FetchResult
    {
        $destDir = $this->downloadDir ?? sys_get_temp_dir();
        $zipFile = $destDir . '/' . self::SOURCE_ID . '_' . date('Ymd_His') . '.zip';
        $csvFile = $destDir . '/' . self::SOURCE_ID . '_' . date('Ymd_His') . '.csv';

        $url = sprintf(self::URL, $this->apiKey);

        // the key rides in the query string, so the logged url is redacted
        $this->logger->info("Fetching US SAM.gov exclusions extract", ['url' => 'api.sam.gov/data-services/v1/extracts']);

        $fp = fopen($zipFile, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open file for writing: {$zipFile}");
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 600,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_USERAGENT => Config::USER_AGENT,
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($httpCode !== 200) {
            @unlink($zipFile);
            throw new \RuntimeException("Failed to fetch SAM exclusions: HTTP {$httpCode} - {$error}");
        }

        $zipSize = filesize($zipFile);
        if ($zipSize === 0 || $zipSize === false) {
            @unlink($zipFile);
            throw new \RuntimeException("SAM.gov returned empty response");
        }

        $this->logger->info("SAM.gov ZIP downloaded", ['size' => $zipSize]);

        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            @unlink($zipFile);
            throw new \RuntimeException("Failed to open SAM exclusions ZIP");
        }

        $csvName = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_contains($name, 'Exclusions') && str_ends_with(strtolower($name), '.csv')) {
                $csvName = $name;
                break;
            }
        }

        if ($csvName === null) {
            $zip->close();
            @unlink($zipFile);
            throw new \RuntimeException("No exclusions CSV found in ZIP");
        }

        // stream the entry to a path we control: entry names come off the
        // network and must never dictate the write path (zip-slip hardening)
        $in = $zip->getStream($csvName);
        if ($in === false) {
            $zip->close();
            @unlink($zipFile);
            throw new \RuntimeException("Failed to read CSV entry from ZIP");
        }

        $out = fopen($csvFile, 'w');
        if ($out === false) {
            fclose($in);
            $zip->close();
            @unlink($zipFile);
            throw new \RuntimeException("Failed to open file for writing: {$csvFile}");
        }

        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
        $zip->close();
        @unlink($zipFile);

        $fileSize = filesize($csvFile);
        $hash = hash_file('sha256', $csvFile);

        if ($lastHash !== null && $hash === $lastHash) {
            @unlink($csvFile);
            return FetchResult::unchanged($hash);
        }

        $this->logger->info("SAM.gov exclusions extracted", [
            'csv_size' => $fileSize,
            'hash' => $hash,
        ]);

        return new FetchResult(
            rawContent: $csvFile,
            hash: $hash,
            changed: true,
            delta: false,
            deltaChangeset: null,
            meta: [
                'http_code' => (int)$httpCode,
                'file_size' => $fileSize,
                'zip_size' => $zipSize,
                'fetched_at' => date('Y-m-d H:i:s'),
                'is_file_path' => true,
            ]
        );
    }

    public function getParser(): ParserInterface
    {
        return new USGovSAMParser($this->logger);
    }
}
