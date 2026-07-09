<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;

/**
 * Parser for Belgium SIFI consolidated sanctions list.
 *
 * CSV format (semicolon-delimited):
 * Lastname;Firstname;Middlename;Wholename;Gender;Birth date;Birth place;Birth country;
 * Function;Number;Remark;Embargos;type;Regulation;Publication date;Links
 *
 * The list contains both Belgian national designations (type=BE) and EU designations (type=TAQA etc).
 * Multiple rows can represent the same person with different aliases or regulations.
 * Wholename is the grouping key since there's no explicit ID.
 */
class BelgiumSIFICSVParser implements ParserInterface
{
    private LoggerInterface $logger;
    private int $errorCount = 0;

    private const COL_LASTNAME = 0;
    private const COL_FIRSTNAME = 1;
    private const COL_MIDDLENAME = 2;
    private const COL_WHOLENAME = 3;
    private const COL_GENDER = 4;
    private const COL_BIRTHDATE = 5;
    private const COL_BIRTHPLACE = 6;
    private const COL_BIRTHCOUNTRY = 7;
    private const COL_FUNCTION = 8;
    private const COL_NUMBER = 9;
    private const COL_REMARK = 10;
    private const COL_EMBARGOS = 11;
    private const COL_TYPE = 12;
    private const COL_REGULATION = 13;
    private const COL_PUBDATE = 14;
    private const COL_LINKS = 15;

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
        $this->logger->info("Starting Belgium SIFI CSV parse", [
            'file' => $filePath,
            'source_id' => $sourceId,
        ]);

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Failed to read file: {$filePath}");
        }

        // Skip header
        if (fgets($handle) === false) {
            fclose($handle);
            throw new \RuntimeException("Belgium CSV has no data rows");
        }

        $groups = [];
        $errors = 0;
        $rowNum = 0;

        // streamed so that quoted fields with embedded newlines survive
        while (($row = fgetcsv($handle, 0, ';', '"')) !== false) {
            if ($row === [null]) continue;
            if (count($row) === 1 && trim((string) ($row[0] ?? '')) === '') continue;
            $rowNum++;

            $wholename = trim($row[self::COL_WHOLENAME] ?? '');
            if ($wholename === '') continue;

            // Clean quotes from names
            $wholename = trim($wholename, ' "');

            // Use wholename + birthdate as grouping key for deduplication
            $dob = trim($row[self::COL_BIRTHDATE] ?? '');
            $groupKey = mb_strtolower($wholename) . '|' . $dob;

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [];
            }
            $groups[$groupKey][] = $row;
        }

        fclose($handle);

        $this->logger->info("Belgium SIFI rows grouped", [
            'total_rows' => $rowNum,
            'unique_entities' => count($groups),
        ]);

        $entities = [];
        foreach ($groups as $groupKey => $rows) {
            try {
                $entity = $this->parseGroup($groupKey, $rows, $sourceId);
                if ($entity !== null) {
                    $entities[] = $entity;
                }
            } catch (\Exception $e) {
                $errors++;
                if ($errors <= 10) {
                    $this->logger->error("Failed to parse Belgium SIFI group", [
                        'group' => $groupKey,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logger->info("Belgium SIFI parse complete", [
            'source_id' => $sourceId,
            'entities' => count($entities),
            'errors' => $errors,
        ]);

        $this->errorCount = $errors;

        return $entities;
    }

    private function parseGroup(string $groupKey, array $rows, string $sourceId): ?SanctionedEntity
    {
        $firstRow = $rows[0];

        $wholename = trim(trim($firstRow[self::COL_WHOLENAME] ?? ''), ' "');
        if ($wholename === '') return null;

        $lastname = trim(trim($firstRow[self::COL_LASTNAME] ?? ''), ' "');
        $firstname = trim(trim($firstRow[self::COL_FIRSTNAME] ?? ''), ' "');
        $gender = strtoupper(trim($firstRow[self::COL_GENDER] ?? ''));

        // All entries appear to be individuals (persons)
        $entityType = 'individual';

        // DOB
        $dates = [];
        $dobRaw = trim($firstRow[self::COL_BIRTHDATE] ?? '');
        if ($dobRaw !== '') {
            $parsed = $this->parseDOB($dobRaw);
            if ($parsed !== null) {
                $dates[] = [
                    'type' => 'date_of_birth',
                    'value' => $parsed,
                    'circa' => false,
                ];
            }
        }

        // Nationalities from birth country
        $nationalities = [];
        $birthCountry = trim($firstRow[self::COL_BIRTHCOUNTRY] ?? '');
        if ($birthCountry !== '' && strlen($birthCountry) <= 3) {
            $nationalities[] = strtoupper($birthCountry);
        }

        // Programs and regulations from all rows
        $programs = [];
        $regulations = [];
        $identifiers = [];
        $seenNrn = [];

        foreach ($rows as $row) {
            $embargo = trim($row[self::COL_EMBARGOS] ?? '');
            if ($embargo !== '' && !in_array($embargo, $programs, true)) {
                $programs[] = $embargo;
            }

            $type = trim($row[self::COL_TYPE] ?? '');
            if ($type !== '' && !in_array($type, $programs, true)) {
                $programs[] = $type;
            }

            $regulation = trim($row[self::COL_REGULATION] ?? '');
            if ($regulation !== '') {
                $regulations[] = $regulation;
            }

            // National Register Number as identifier
            $number = trim($row[self::COL_NUMBER] ?? '');
            if ($number !== '' && !isset($seenNrn[$number])) {
                $seenNrn[$number] = true;
                // Parse "NRN 87.02.28-365.54" format
                if (str_starts_with($number, 'NRN ')) {
                    $identifiers[] = [
                        'type' => 'National Register Number',
                        'value' => substr($number, 4),
                        'country' => 'BE',
                        'valid' => true,
                    ];
                } else {
                    $identifiers[] = [
                        'type' => 'National ID',
                        'value' => $number,
                        'country' => 'BE',
                        'valid' => true,
                    ];
                }
            }
        }

        // Remarks from function + remark fields
        $remarkParts = [];
        $function = trim($firstRow[self::COL_FUNCTION] ?? '');
        if ($function !== '') {
            $remarkParts[] = $function;
        }
        $remark = trim($firstRow[self::COL_REMARK] ?? '');
        if ($remark !== '') {
            $remarkParts[] = $remark;
        }
        $birthPlace = trim($firstRow[self::COL_BIRTHPLACE] ?? '');
        if ($birthPlace !== '') {
            $remarkParts[] = "POB: {$birthPlace}";
        }
        $remarks = !empty($remarkParts) ? implode('; ', $remarkParts) : null;
        if ($remarks !== null && strlen($remarks) > 1000) {
            $remarks = mb_substr($remarks, 0, 1000);
        }

        // Listed date from first publication date
        $listedDate = null;
        foreach ($rows as $row) {
            $pubDate = trim($row[self::COL_PUBDATE] ?? '');
            if ($pubDate !== '') {
                $listedDate = $this->parsePubDate($pubDate);
                if ($listedDate !== null) break;
            }
        }

        // Generate stable ID from name + dob
        $sourceEntityId = 'BE_' . substr(hash('sha256', $groupKey), 0, 16);

        return new SanctionedEntity(
            sourceEntityId: $sourceEntityId,
            sourceId: $sourceId,
            entityType: $entityType,
            primaryName: $wholename,
            aliases: [],
            dates: $dates,
            nationalities: $nationalities,
            identifiers: $identifiers,
            addresses: [],
            programs: $programs,
            listedDate: $listedDate,
            remarks: $remarks,
            raw: [
                'gender' => $gender,
                'regulations' => implode('; ', array_unique($regulations)),
            ]
        );
    }

    /**
     * Parse Belgian date formats: "28-02-87", "1964-04-04", "22-09-90"
     */
    private function parseDOB(string $dob): ?string
    {
        $dob = trim($dob);
        if ($dob === '') return null;

        // ISO format: 1964-04-04
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dob, $m)) {
            return $dob;
        }

        // Belgian short format: DD-MM-YY
        if (preg_match('/^(\d{2})-(\d{2})-(\d{2})$/', $dob, $m)) {
            $day = (int)$m[1];
            $month = (int)$m[2];
            $yearShort = (int)$m[3];
            $year = 2000 + $yearShort;
            // DOB pivot uses >=: nobody born in the current year appears on a
            // sanctions list, so a two-digit year landing on this year is 19xx
            if ($year >= (int) date("Y")) {
                $year -= 100;
            }
            return sprintf('%d-%02d-%02d', $year, $month, $day);
        }

        return null;
    }

    /**
     * Parse publication date: "01-06-16", "28-07-16" (DD-MM-YY)
     */
    private function parsePubDate(string $date): ?string
    {
        $date = trim($date);
        if ($date === '') return null;

        if (preg_match('/^(\d{2})-(\d{2})-(\d{2})$/', $date, $m)) {
            $day = (int)$m[1];
            $month = (int)$m[2];
            $yearShort = (int)$m[3];
            $year = 2000 + $yearShort;
            if ($year > (int) date("Y")) {
                $year -= 100;
            }
            return sprintf('%d-%02d-%02d', $year, $month, $day);
        }

        return null;
    }
}