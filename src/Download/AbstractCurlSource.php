<?php

namespace SanctionsEtl\Download;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Config;

/**
 * Shared curl fetch for sources that publish a single downloadable file.
 * Handles streaming to disk, transfer error detection, one retry on
 * transient network failures, and hash based change detection.
 */
abstract class AbstractCurlSource implements SourceInterface
{
    protected const TIMEOUT = 600;
    protected const CONNECT_TIMEOUT = 30;
    protected const RETRIES = 1;

    protected LoggerInterface $logger;
    protected ?string $downloadDir;

    public function __construct(LoggerInterface $logger, ?string $downloadDir = null)
    {
        $this->logger = $logger;
        $this->downloadDir = $downloadDir;
    }

    abstract protected function url(): string;

    /**
     * Extra curl options for sources with quirks: forced HTTP version,
     * auth or browser-mimicking headers, alternate user agent.
     * Values returned here take precedence over the defaults.
     */
    protected function curlOptions(): array
    {
        return [];
    }

    public function getUrls(): array
    {
        return ['full' => $this->url()];
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
        $destFile = $destDir . '/' . $this->getSourceId() . '_' . date('Ymd_His') . '.' . $this->getFormat();

        $attempts = static::RETRIES + 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->logger->info("Fetching {$this->getDisplayName()}", [
                'url' => $this->url(),
                'dest_file' => $destFile,
                'attempt' => $attempt,
            ]);

            try {
                $this->download($destFile);
                break;
            } catch (\RuntimeException $e) {
                @unlink($destFile);
                if ($attempt === $attempts) {
                    throw $e;
                }
                // some sources (SECO especially) drop transfers midway on a
                // regular basis, so a transient failure gets one retry before
                // the sync records an error
                $this->logger->warning("Fetch failed, retrying", [
                    'source_id' => $this->getSourceId(),
                    'error' => $e->getMessage(),
                ]);
                sleep(2);
            }
        }

        $fileSize = filesize($destFile);
        if ($fileSize === 0) {
            @unlink($destFile);
            throw new \RuntimeException("{$this->getDisplayName()} returned empty response");
        }

        $hash = hash_file('sha256', $destFile);

        if ($lastHash !== null && $hash === $lastHash) {
            @unlink($destFile);
            return FetchResult::unchanged($hash);
        }

        $this->logger->info("{$this->getDisplayName()} fetched", [
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
                'file_size' => $fileSize,
                'url' => $this->url(),
                'fetched_at' => date('Y-m-d H:i:s'),
                'is_file_path' => true,
            ]
        );
    }

    private function download(string $destFile): void
    {
        $fp = fopen($destFile, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Failed to open file for writing: {$destFile}");
        }

        $ch = curl_init();
        // subclass options come first: array union keeps left-hand values on key conflicts
        curl_setopt_array($ch, $this->curlOptions() + [
            CURLOPT_URL => $this->url(),
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => static::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => static::CONNECT_TIMEOUT,
            CURLOPT_USERAGENT => Config::USER_AGENT,
        ]);

        $ok = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $errno !== 0 || $httpCode !== 200) {
            throw new \RuntimeException(
                "Failed to fetch {$this->getDisplayName()}: HTTP {$httpCode}, curl errno {$errno} - {$error}"
            );
        }
    }
}
