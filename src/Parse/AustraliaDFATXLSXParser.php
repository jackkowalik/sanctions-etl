<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AustraliaDFATXLSXParser implements ParserInterface
{
    private LoggerInterface $logger;
    private int $errorCount = 0;

    private const COL_REFERENCE = 0;
    private const COL_NAME = 1;
    private const COL_TYPE = 2;
    private const COL_NAME_TYPE = 3;
    private const COL_ALIAS_STRENGTH = 4;
    private const COL_DOB = 5;
    private const COL_POB = 6;
    private const COL_CITIZENSHIP = 7;
    private const COL_ADDRESS = 8;
    private const COL_ADDITIONAL_INFO = 9;
    private const COL_LISTING_INFO = 10;
    private const COL_IMO = 11;
    private const COL_COMMITTEES = 12;
    private const COL_CONTROL_DATE = 13;
    private const COL_INSTRUMENT = 14;
    private const COL_TFS = 15;
    private const COL_TRAVEL_BAN = 16;
    private const COL_ARMS_EMBARGO = 17;
    private const COL_MARITIME = 18;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function parse(string $filePath, string $sourceId): array
    {
        $this->logger->info("Starting Australia DFAT XLSX parse", [
            'file' => $filePath,
            'source_id' => $sourceId,
        ]);

        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = $sheet->getHighestRow();

        $this->logger->info("DFAT spreadsheet loaded", ['rows' => $highestRow]);

        $groups = [];
        $errors = 0;

        for ($r = 2; $r <= $highestRow; $r++) {
            try {
                $row = $sheet->rangeToArray("A{$r}:S{$r}")[0];

                $ref = trim((string)($row[self::COL_REFERENCE] ?? ''));
                if ($ref === '') continue;

                $baseRef = $this->extractBaseRef($ref);
                if ($baseRef === '') continue;

                if (!isset($groups[$baseRef])) {
                    $groups[$baseRef] = [];
                }
                $groups[$baseRef][] = $row;
            } catch (\Exception $e) {
                $errors++;
                if ($errors <= 10) {
                    $this->logger->error("Failed to read DFAT row", [
                        'row' => $r,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $this->logger->info("DFAT rows grouped", [
            'unique_entities' => count($groups),
            'errors' => $errors,
        ]);

        $entities = [];
        foreach ($groups as $baseRef => $rows) {
            try {
                $entity = $this->parseGroup($baseRef, $rows, $sourceId);
                if ($entity !== null) {
                    $entities[] = $entity;
                }
            } catch (\Exception $e) {
                $errors++;
                if ($errors <= 10) {
                    $this->logger->error("Failed to parse DFAT group", [
                        'ref' => $baseRef,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logger->info("Australia DFAT parse complete", [
            'source_id' => $sourceId,
            'entities' => count($entities),
            'errors' => $errors,
        ]);

        $this->errorCount = $errors;

        return $entities;
    }

    /**
     * Extract the numeric base from a reference like "2", "2a", "2b".
     * All rows sharing the same base ref belong to one entity.
     */
    private function extractBaseRef(string $ref): string
    {
        if (preg_match('/^(\d+)/', $ref, $m)) {
            return $m[1];
        }
        return '';
    }

    private function parseGroup(string $baseRef, array $rows, string $sourceId): ?SanctionedEntity
    {
        $primaryName = '';
        $entityType = 'unknown';
        $aliases = [];
        $dates = [];
        $nationalities = [];
        $addresses = [];
        $programs = [];
        $remarks = null;
        $listedDate = null;

        $seenDobs = [];
        $seenAddrs = [];

        foreach ($rows as $row) {
            $name = trim((string)($row[self::COL_NAME] ?? ''));
            $type = strtolower(trim((string)($row[self::COL_TYPE] ?? '')));
            $nameType = strtolower(trim((string)($row[self::COL_NAME_TYPE] ?? '')));
            $aliasStrength = strtolower(trim((string)($row[self::COL_ALIAS_STRENGTH] ?? '')));

            if ($name === '') continue;

            // Resolve entity type from the first row that has it
            if ($entityType === 'unknown' && $type !== '') {
                $entityType = match (true) {
                    str_contains($type, 'individual') => 'individual',
                    str_contains($type, 'entity') => 'organization',
                    str_contains($type, 'vessel') => 'vessel',
                    default => 'unknown',
                };
            }

            if ($nameType === 'primary name' && $primaryName === '') {
                $primaryName = $name;
            } elseif ($nameType === 'original script') {
                $aliases[] = [
                    'name' => $name,
                    'type' => 'transliteration',
                    'low_quality' => false,
                ];
            } elseif ($nameType === 'alias' || $nameType === '') {
                $isLowQuality = $aliasStrength === 'weak';
                $aliases[] = [
                    'name' => $name,
                    'type' => 'aka',
                    'low_quality' => $isLowQuality,
                ];
            } else {
                $aliases[] = [
                    'name' => $name,
                    'type' => 'aka',
                    'low_quality' => false,
                ];
            }

            // DOB - can contain comma-separated years like "1945, 1946, 1947"
            $dobRaw = trim((string)($row[self::COL_DOB] ?? ''));
            if ($dobRaw !== '' && !isset($seenDobs[$dobRaw])) {
                $seenDobs[$dobRaw] = true;
                $parsedDobs = $this->parseDOB($dobRaw);
                foreach ($parsedDobs as $dob) {
                    $dates[] = $dob;
                }
            }

            // Citizenship
            $cit = trim((string)($row[self::COL_CITIZENSHIP] ?? ''));
            if ($cit !== '' && !in_array($cit, $nationalities, true)) {
                $nationalities[] = $cit;
            }

            // Address
            $addr = trim((string)($row[self::COL_ADDRESS] ?? ''));
            if ($addr !== '' && !isset($seenAddrs[$addr])) {
                $seenAddrs[$addr] = true;
                $addresses[] = [
                    'full' => $addr,
                    'city' => '',
                    'region' => '',
                    'postal' => '',
                    'country' => '',
                ];
            }

            // Committees as programs
            $committee = trim((string)($row[self::COL_COMMITTEES] ?? ''));
            if ($committee !== '' && !in_array($committee, $programs, true)) {
                $programs[] = $committee;
            }

            // Additional info as remarks (take first non-empty)
            $info = trim((string)($row[self::COL_ADDITIONAL_INFO] ?? ''));
            if ($info !== '' && $remarks === null) {
                $remarks = strlen($info) > 1000 ? mb_substr($info, 0, 1000) : $info;
            }

            // Place of birth appended to remarks
            $pob = trim((string)($row[self::COL_POB] ?? ''));
            if ($pob !== '') {
                $pobStr = "POB: {$pob}";
                if ($remarks === null) {
                    $remarks = $pobStr;
                } elseif (!str_contains($remarks, $pobStr)) {
                    $remarks .= '; ' . $pobStr;
                    if (strlen($remarks) > 1000) {
                        $remarks = mb_substr($remarks, 0, 1000);
                    }
                }
            }

            // Listed date from control date (first non-empty)
            if ($listedDate === null) {
                $controlDate = $row[self::COL_CONTROL_DATE] ?? '';
                if ($controlDate !== '') {
                    $listedDate = $this->parseControlDate($controlDate);
                }
            }
        }

        if ($primaryName === '') return null;

        // Build sanction type programs from boolean columns
        $firstRow = $rows[0];
        $sanctionTypes = [];
        if ($this->isTruthy($firstRow[self::COL_TFS] ?? '')) $sanctionTypes[] = 'Targeted Financial Sanction';
        if ($this->isTruthy($firstRow[self::COL_TRAVEL_BAN] ?? '')) $sanctionTypes[] = 'Travel Ban';
        if ($this->isTruthy($firstRow[self::COL_ARMS_EMBARGO] ?? '')) $sanctionTypes[] = 'Arms Embargo';
        if ($this->isTruthy($firstRow[self::COL_MARITIME] ?? '')) $sanctionTypes[] = 'Maritime Restriction';

        // IMO number as identifier for vessels
        $identifiers = [];
        $imo = trim((string)($firstRow[self::COL_IMO] ?? ''));
        if ($imo !== '') {
            $identifiers[] = [
                'type' => 'IMO',
                'value' => $imo,
                'country' => '',
                'valid' => true,
            ];
        }

        return new SanctionedEntity(
            sourceEntityId: $baseRef,
            sourceId: $sourceId,
            entityType: $entityType,
            primaryName: $primaryName,
            aliases: $aliases,
            dates: $dates,
            nationalities: $nationalities,
            identifiers: $identifiers,
            addresses: $addresses,
            programs: array_merge($programs, $sanctionTypes),
            listedDate: $listedDate,
            remarks: $remarks,
            raw: [
                'instrument' => trim((string)($firstRow[self::COL_INSTRUMENT] ?? '')),
                'listing_info' => trim((string)($firstRow[self::COL_LISTING_INFO] ?? '')),
            ]
        );
    }

    /**
     * Parse DOB field. Can be:
     *   - "1945, 1946, 1947" (multiple approximate years)
     *   - "1980-01-15" (ISO date)
     *   - "15/01/1980" (AU format)
     *   - "1980" (year only)
     */
    private function parseDOB(string $dob): array
    {
        $results = [];

        // Multiple comma-separated values
        $parts = array_map('trim', explode(',', $dob));

        foreach ($parts as $part) {
            if ($part === '') continue;

            // Year only
            if (preg_match('/^\d{4}$/', $part)) {
                $results[] = [
                    'type' => 'date_of_birth',
                    'value' => $part,
                    'circa' => count($parts) > 1,
                ];
                continue;
            }

            // ISO format YYYY-MM-DD
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $part)) {
                $results[] = [
                    'type' => 'date_of_birth',
                    'value' => $part,
                    'circa' => false,
                ];
                continue;
            }

            // AU format DD/MM/YYYY
            if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $part, $m)) {
                $results[] = [
                    'type' => 'date_of_birth',
                    'value' => sprintf('%s-%02d-%02d', $m[3], (int)$m[2], (int)$m[1]),
                    'circa' => false,
                ];
                continue;
            }

            // Excel date (numeric) - PhpSpreadsheet may return a float
            if (is_numeric($part) && (float)$part > 10000) {
                try {
                    $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$part);
                    $results[] = [
                        'type' => 'date_of_birth',
                        'value' => $date->format('Y-m-d'),
                        'circa' => false,
                    ];
                } catch (\Exception $e) {
                    // skip malformed
                }
                continue;
            }

            // Fallback: store as-is if it looks like a date fragment
            if (strlen($part) <= 20) {
                $results[] = [
                    'type' => 'date_of_birth',
                    'value' => $part,
                    'circa' => false,
                ];
            }
        }

        return $results;
    }

    /**
     * Parse control date. Can be an ISO datetime string or Excel serial number.
     */
    private function parseControlDate(mixed $val): ?string
    {
        if ($val === '' || $val === null) return null;

        $str = trim((string)$val);

        // "2026-02-05 00:00:00" format
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $str, $m)) {
            return $m[1];
        }

        // Excel serial date number
        if (is_numeric($val) && (float)$val > 10000) {
            try {
                $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$val);
                return $date->format('Y-m-d');
            } catch (\Exception $e) {
                return null;
            }
        }

        return null;
    }

    private function isTruthy(mixed $val): bool
    {
        if ($val === null) return false;
        $str = strtolower(trim((string)$val));
        return $str === 'true' || $str === '1' || $str === 'yes';
    }
}