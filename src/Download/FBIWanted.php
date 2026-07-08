<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Config;
use Psr\Log\LoggerInterface;
use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\FBIWantedParser;

class FBIWanted implements SourceInterface
{
    private const API_URL = 'https://api.fbi.gov/wanted/v1/list';
    private const PAGE_SIZE = 50;
    private const SOURCE_ID = 'us_fbi_wanted';
    private const REQUEST_DELAY_MS = 500;

    private LoggerInterface $logger;
    private ?string $downloadDir;

    public function __construct(LoggerInterface $logger, ?string $downloadDir = null)
    {
        $this->logger = $logger;
        $this->downloadDir = $downloadDir;
    }

    public function getSourceId(): string { return self::SOURCE_ID; }
    public function getDisplayName(): string { return 'FBI Most Wanted'; }
    public function getUrls(): array { return ['full' => self::API_URL]; }
    public function getFormat(): string { return 'json'; }
    public function supportsDelta(): bool { return false; }
    public function getExpectedUpdateFrequency(): int { return 1440; }

    public function fetch(?string $lastHash = null): FetchResult
    {
        $destDir = $this->downloadDir ?? sys_get_temp_dir();
        $destFile = $destDir . '/' . self::SOURCE_ID . '_' . date('Ymd_His') . '.json';

        $this->logger->info("Fetching FBI Wanted list via API", [
            'url' => self::API_URL
        ]);

        $allItems = [];
        $page = 1;
        $totalExpected = null;

        while (true) {
            $url = self::API_URL . '?' . http_build_query([
                'pageSize' => self::PAGE_SIZE,
                'page' => $page,
            ]);

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_USERAGENT => Config::USER_AGENT,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);

            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($httpCode !== 200 || $body === false) {
                if (!empty($allItems)) {
                    $this->logger->error("FBI API failed on page {$page}, using partial data", [
                        'http_code' => $httpCode,
                        'error' => $error,
                        'items_so_far' => count($allItems)
                    ]);
                    break;
                }
                throw new \RuntimeException("FBI API failed: HTTP {$httpCode} - {$error}");
            }

            $data = json_decode($body, true);
            if ($data === null || !isset($data['items'])) {
                if (!empty($allItems)) break;
                throw new \RuntimeException("FBI API returned invalid JSON on page {$page}");
            }

            if ($totalExpected === null) {
                $totalExpected = $data['total'] ?? 0;
                $this->logger->info("FBI API reports {$totalExpected} total entries");
            }

            $items = $data['items'];
            if (empty($items)) break;

            $allItems = array_merge($allItems, $items);

            $this->logger->info("FBI page {$page} fetched", [
                'items_this_page' => count($items),
                'total_collected' => count($allItems)
            ]);

            if (count($allItems) >= $totalExpected) break;
            if (count($items) < self::PAGE_SIZE) break;

            $page++;
            usleep(self::REQUEST_DELAY_MS * 1000);
        }

        if (empty($allItems)) {
            throw new \RuntimeException("FBI API returned zero items");
        }

        $jsonContent = json_encode([
            'total' => count($allItems),
            'fetched_at' => date('Y-m-d H:i:s'),
            'items' => $allItems,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        file_put_contents($destFile, $jsonContent);

        $fileSize = filesize($destFile);
        $hash = hash_file('sha256', $destFile);

        if ($lastHash !== null && $hash === $lastHash) {
            @unlink($destFile);
            return FetchResult::unchanged($hash);
        }

        $this->logger->info("FBI data fetched and saved", [
            'total_items' => count($allItems),
            'file_size' => $fileSize,
            'hash' => $hash,
            'pages_fetched' => $page,
        ]);

        return new FetchResult(
            rawContent: $destFile,
            hash: $hash,
            changed: true,
            delta: false,
            deltaChangeset: null,
            meta: [
                'file_size' => $fileSize,
                'url' => self::API_URL,
                'fetched_at' => date('Y-m-d H:i:s'),
                'is_file_path' => true,
                'total_items' => count($allItems),
                'pages_fetched' => $page,
            ]
        );
    }

    public function getParser(): ParserInterface
    {
        return new FBIWantedParser($this->logger);
    }
}