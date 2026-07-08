<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;

class WorldBankDebarredParser implements ParserInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function parse(string $filePath, string $sourceId): array
    {
        $this->logger->info("Starting World Bank debarred parse", [
            'file' => $filePath,
            'source_id' => $sourceId,
        ]);

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read file: {$filePath}");
        }

        $data = json_decode($raw, true);
        if ($data === null) {
            throw new \RuntimeException("Invalid JSON in: {$filePath}");
        }

        $records = $this->extractRecords($data);
        if (empty($records)) {
            throw new \RuntimeException("No records found in World Bank response");
        }

        $this->logger->info("World Bank records extracted", ['count' => count($records)]);

        $entities = [];
        $errors = 0;

        foreach ($records as $record) {
            try {
                $entity = $this->parseRecord($record, $sourceId);
                if ($entity !== null) {
                    $entities[] = $entity;
                }
            } catch (\Exception $e) {
                $errors++;
                if ($errors <= 10) {
                    $this->logger->error("Failed to parse World Bank record", [
                        'name' => $record['SUPP_NAME'] ?? '?',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logger->info("World Bank parse complete", [
            'source_id' => $sourceId,
            'entities' => count($entities),
            'errors' => $errors,
        ]);

        return $entities;
    }

    /**
     * Navigate the nested JSON response to find the records array.
     * Structure: { "d": { "results": [ ... ] } } or similar nested wrapper.
     */
    private function extractRecords(array $data): array
    {
        // Walk into nested objects until we find an array
        $current = $data;
        $depth = 0;

        while ($depth < 5) {
            if (isset($current[0])) {
                return $current;
            }

            $keys = array_keys($current);
            if (count($keys) === 1) {
                $current = $current[$keys[0]];
                $depth++;
                continue;
            }

            // Multiple keys, look for one that contains an array
            foreach ($keys as $key) {
                if (is_array($current[$key]) && isset($current[$key][0])) {
                    return $current[$key];
                }
            }

            // Look for nested object
            foreach ($keys as $key) {
                if (is_array($current[$key]) && !empty($current[$key])) {
                    $current = $current[$key];
                    $depth++;
                    continue 2;
                }
            }

            break;
        }

        return [];
    }

    private function parseRecord(array $record, string $sourceId): ?SanctionedEntity
    {
        $name = trim($record['SUPP_NAME'] ?? '');
        if ($name === '') return null;

        $suppId = (string)($record['SUPP_ID'] ?? '');
        if ($suppId === '' || $suppId === '0') return null;

        $typeCode = strtoupper(trim($record['SUPP_TYPE_CODE'] ?? ''));
        $entityType = match ($typeCode) {
            'I' => 'individual',
            'F' => 'organization',
            'C' => 'organization',
            default => $this->inferEntityType($name),
        };

        // Address
        $addresses = [];
        $addrParts = array_filter([
            trim($record['SUPP_ADDR'] ?? ''),
            trim($record['SUPP_CITY'] ?? ''),
            trim($record['SUPP_PROV_NAME'] ?? ''),
            trim($record['SUPP_ZIP_CODE'] ?? $record['SUPP_POST_CODE'] ?? ''),
        ]);
        $country = trim($record['LAND1'] ?? '');
        $countryName = trim($record['COUNTRY_NAME'] ?? '');

        if (!empty($addrParts) || $country !== '') {
            $addresses[] = [
                'full' => implode(', ', $addrParts),
                'city' => trim($record['SUPP_CITY'] ?? ''),
                'region' => trim($record['SUPP_PROV_NAME'] ?? $record['SUPP_STATE_CODE'] ?? ''),
                'postal' => trim($record['SUPP_ZIP_CODE'] ?? $record['SUPP_POST_CODE'] ?? ''),
                'country' => $country,
            ];
        }

        // Programs from debarment reason and eligibility status
        $programs = [];
        $debarReason = trim($record['DEBAR_REASON'] ?? '');
        $eligStat = trim($record['ELIG_STAT'] ?? '');

        if ($eligStat !== '') {
            $programs[] = $eligStat;
        }
        if ($debarReason !== '') {
            $programs[] = $debarReason;
        }

        // Remarks
        $remarksParts = [];
        if ($debarReason !== '') {
            $remarksParts[] = $debarReason;
        }
        $addInfo = trim($record['ADD_SUPP_INFO'] ?? '');
        if ($addInfo !== '') {
            $remarksParts[] = $addInfo;
        }
        if ($countryName !== '') {
            $remarksParts[] = "Country: {$countryName}";
        }
        $remarks = !empty($remarksParts) ? implode('; ', $remarksParts) : null;
        if ($remarks !== null && strlen($remarks) > 1000) {
            $remarks = substr($remarks, 0, 1000);
        }

        // Listed date from debarment start
        $listedDate = $this->parseDate($record['DEBAR_FROM_DATE'] ?? '');

        // Nationalities from country code
        $nationalities = [];
        if ($country !== '') {
            $nationalities[] = $country;
        }

        // Identifiers
        $identifiers = [];
        $dnbId = trim($record['DNB_ID'] ?? '');
        if ($dnbId !== '' && $dnbId !== '0') {
            $identifiers[] = [
                'type' => 'DUNS',
                'value' => $dnbId,
                'country' => '',
                'valid' => true,
            ];
        }

        return new SanctionedEntity(
            sourceEntityId: $suppId,
            sourceId: $sourceId,
            entityType: $entityType,
            primaryName: $name,
            aliases: [],
            dates: [],
            nationalities: $nationalities,
            identifiers: $identifiers,
            addresses: $addresses,
            programs: $programs,
            listedDate: $listedDate,
            remarks: $remarks,
            raw: [
                'supp_type_code' => $typeCode,
                'elig_stat' => $eligStat,
                'debar_to_date' => $record['DEBAR_TO_DATE'] ?? '',
                'un_supp_flg' => $record['UN_SUPP_FLG'] ?? '',
                'cris_supp_id' => $record['CRIS_SUPP_ID'] ?? '',
            ]
        );
    }

    private function inferEntityType(string $name): string
    {
        $lower = mb_strtolower($name);
        $orgIndicators = [
            'ltd', 'limited', 'llc', 'inc', 'corp', 'co.', 'company',
            'gmbh', 'sa', 'srl', 'group', 'institute', 'university',
            'foundation', 'association', 'ministry', 'bureau', 'bank',
            'enterprise', 'industries', 'construction', 'consulting',
            'services', 'solutions', 'technologies', 'systems',
        ];

        foreach ($orgIndicators as $indicator) {
            if (str_contains($lower, $indicator)) {
                return 'organization';
            }
        }

        return 'unknown';
    }

    private function parseDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') return null;

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $date, $m)) {
            return $m[1];
        }

        return null;
    }
}