<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;
use SanctionsEtl\Download\USConsolidatedScreeningList;

class USCSLParser implements ParserInterface
{
    private LoggerInterface $logger;
    private string $targetSourceId;

    public function __construct(LoggerInterface $logger, string $targetSourceId)
    {
        $this->logger = $logger;
        $this->targetSourceId = $targetSourceId;
    }

    public function parse(string $filePath, string $sourceId): array
    {
        $this->logger->info("Starting US CSL parse", [
            'file' => $filePath,
            'source_id' => $sourceId,
            'target_source_id' => $this->targetSourceId
        ]);

        $sourceMap = USConsolidatedScreeningList::SOURCE_MAP;
        $reverseMap = array_flip($sourceMap);
        $targetCsvSource = $reverseMap[$this->targetSourceId] ?? null;

        if ($targetCsvSource === null) {
            throw new \RuntimeException("No CSV source mapping for: {$this->targetSourceId}");
        }

        $f = fopen($filePath, 'r');
        if ($f === false) {
            throw new \RuntimeException("Failed to open CSV: {$filePath}");
        }

        $header = fgetcsv($f);
        if ($header === false || count($header) < 20) {
            fclose($f);
            throw new \RuntimeException("Invalid CSV header in: {$filePath}");
        }

        $entities = [];
        $errors = 0;
        $skipped = 0;
        $rowNum = 0;

        while (($data = fgetcsv($f)) !== false) {
            $rowNum++;

            if (count($data) !== count($header)) {
                $errors++;
                continue;
            }

            $row = array_combine($header, $data);
            $csvSource = $row['source'] ?? '';

            if ($csvSource !== $targetCsvSource) {
                $skipped++;
                continue;
            }

            try {
                $entity = $this->parseRow($row, $sourceId);
                if ($entity !== null) {
                    $entities[] = $entity;
                }
            } catch (\Exception $e) {
                $errors++;
                if ($errors <= 10) {
                    $this->logger->error("Failed to parse CSL row", [
                        'row' => $rowNum,
                        'name' => $row['name'] ?? '?',
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        fclose($f);

        $this->logger->info("US CSL parse complete", [
            'source_id' => $sourceId,
            'target_csv_source' => $targetCsvSource,
            'entities' => count($entities),
            'skipped' => $skipped,
            'errors' => $errors
        ]);

        return $entities;
    }

    private function parseRow(array $row, string $sourceId): ?SanctionedEntity
    {
        $name = trim($row['name'] ?? '');
        if ($name === '') return null;

        $cslId = trim($row['_id'] ?? '');
        if ($cslId === '') return null;

        // Entity type: Treasury lists have it, BIS/State lists don't
        $typeRaw = strtolower(trim($row['type'] ?? ''));
        $entityType = match (true) {
            str_contains($typeRaw, 'individual') => 'individual',
            str_contains($typeRaw, 'vessel') => 'vessel',
            str_contains($typeRaw, 'aircraft') => 'aircraft',
            $typeRaw === 'entity' => 'organization',
            default => $this->inferEntityType($name),
        };

        // Programs: semicolon-separated
        $programs = $this->parseSemicolonList($row['programs'] ?? '');

        // Aliases: semicolon-separated
        $aliases = [];
        foreach ($this->parseSemicolonList($row['alt_names'] ?? '') as $altName) {
            if ($altName !== $name) {
                $aliases[] = [
                    'name' => $altName,
                    'type' => 'aka',
                    'low_quality' => false,
                ];
            }
        }

        // Addresses: semicolon-separated, last 2 chars before semicolon are country code
        $addresses = $this->parseAddresses($row['addresses'] ?? '');

        // DOB
        $dates = [];
        $dobRaw = trim($row['dates_of_birth'] ?? '');
        if ($dobRaw !== '') {
            foreach ($this->parseSemicolonList($dobRaw) as $dob) {
                $dates[] = [
                    'type' => 'date_of_birth',
                    'value' => $dob,
                    'circa' => false,
                ];
            }
        }

        // Nationalities
        $nationalities = $this->parseSemicolonList($row['nationalities'] ?? '');

        // Citizenships (merge with nationalities)
        foreach ($this->parseSemicolonList($row['citizenships'] ?? '') as $cit) {
            if (!in_array($cit, $nationalities, true)) {
                $nationalities[] = $cit;
            }
        }

        // Identifiers: format "Type, Value, Country; Type, Value, Country"
        $identifiers = $this->parseIdentifiers($row['ids'] ?? '');

        // Remarks
        $remarks = trim($row['remarks'] ?? '') ?: null;
        if ($remarks !== null && strlen($remarks) > 1000) {
            $remarks = mb_substr($remarks, 0, 1000);
        }

        // Listed date
        $listedDate = $this->parseDate($row['start_date'] ?? '');

        return new SanctionedEntity(
            sourceEntityId: $cslId,
            sourceId: $sourceId,
            entityType: $entityType,
            primaryName: $name,
            aliases: $aliases,
            dates: $dates,
            nationalities: $nationalities,
            identifiers: $identifiers,
            addresses: $addresses,
            programs: $programs,
            listedDate: $listedDate,
            remarks: $remarks,
            raw: [
                'entity_number' => $row['entity_number'] ?? '',
                'federal_register_notice' => $row['federal_register_notice'] ?? '',
                'source_list_url' => $row['source_list_url'] ?? '',
                'license_requirement' => $row['license_requirement'] ?? '',
                'license_policy' => $row['license_policy'] ?? '',
                'end_date' => $row['end_date'] ?? '',
                'standard_order' => $row['standard_order'] ?? '',
                'call_sign' => $row['call_sign'] ?? '',
                'vessel_type' => $row['vessel_type'] ?? '',
                'vessel_flag' => $row['vessel_flag'] ?? '',
            ]
        );
    }

    /**
     * Parse semicolon-separated values, trimming whitespace and filtering empties.
     */
    private function parseSemicolonList(string $value): array
    {
        if ($value === '') return [];

        $parts = explode(';', $value);
        $result = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part !== '') {
                $result[] = $part;
            }
        }
        return $result;
    }

    /**
     * Parse CSL address format: "street, city, postal, CC; street2, city2, CC"
     * The last segment before each semicolon is typically a 2-letter country code.
     */
    private function parseAddresses(string $raw): array
    {
        if ($raw === '') return [];

        $addresses = [];
        foreach ($this->parseSemicolonList($raw) as $addrStr) {
            $parts = array_map('trim', explode(',', $addrStr));
            $country = '';

            // Last part is often the 2-letter country code
            $lastPart = end($parts);
            if (strlen($lastPart) === 2 && ctype_alpha($lastPart)) {
                $country = strtoupper($lastPart);
                array_pop($parts);
            }

            $full = implode(', ', $parts);
            if ($full === '' && $country === '') continue;

            // Try to extract city from the second-to-last part
            $city = '';
            if (count($parts) >= 2) {
                $city = $parts[count($parts) - 1];
                // If it looks like a postal code, go one more back
                if (preg_match('/^\d{4,}$/', $city) && count($parts) >= 3) {
                    $city = $parts[count($parts) - 2];
                }
            }

            $addresses[] = [
                'full' => $full,
                'city' => $city,
                'region' => '',
                'postal' => '',
                'country' => $country,
            ];
        }

        return $addresses;
    }

    /**
     * Parse CSL identifier format: "Type, Value, Country; Type, Value, Country"
     * Some identifiers have only 2 parts (Type, Value) without country.
     */
    private function parseIdentifiers(string $raw): array
    {
        if ($raw === '') return [];

        $identifiers = [];
        foreach ($this->parseSemicolonList($raw) as $idStr) {
            $parts = array_map('trim', explode(',', $idStr));

            if (count($parts) < 2) continue;

            $type = $parts[0];
            $value = $parts[1];

            // Skip non-identifier metadata
            $skipTypes = ['Target Type', 'Effective Date (CMIC)', 'Website', 'Executive Order'];
            $skip = false;
            foreach ($skipTypes as $skipType) {
                if (stripos($type, $skipType) !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            $country = '';
            if (count($parts) >= 3) {
                $last = $parts[count($parts) - 1];
                if (strlen($last) === 2 && ctype_alpha($last)) {
                    $country = strtoupper($last);
                }
            }

            if ($value === '') continue;

            $identifiers[] = [
                'type' => $type,
                'value' => $value,
                'country' => $country,
                'valid' => true,
            ];
        }

        return $identifiers;
    }

    /**
     * Infer entity type from name when the type field is empty (BIS/State lists).
     * Names that look like company names -> organization, otherwise unknown.
     */
    private function inferEntityType(string $name): string
    {
        $orgIndicators = [
            'inc.', 'inc,', 'ltd', 'llc', 'corp', 'co.', 'company', 'gmbh',
            'group', 'institute', 'university', 'academy', 'bureau', 'center',
            'centre', 'ministry', 'department', 'laboratory', 'technologies',
            'technology', 'systems', 'industries', 'enterprise', 'foundation',
            'association', 'commission', 'agency', 'bank', 'factory', 'plant',
            'pjsc', 'ojsc', 'jsc', 'oao', 'zao', 'pvt',
        ];

        $lower = mb_strtolower($name);
        foreach ($orgIndicators as $indicator) {
            if (str_contains($lower, $indicator)) {
                return 'organization';
            }
        }

        return 'unknown';
    }

    /**
     * Parse a date string into ISO format.
     * CSL uses YYYY-MM-DD for most entries.
     */
    private function parseDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') return null;

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        if (preg_match('/^\d{4}$/', $date)) {
            return null;
        }

        return null;
    }
}