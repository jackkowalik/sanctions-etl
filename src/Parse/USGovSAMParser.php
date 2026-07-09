<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;

/**
 * Parser for US SAM.gov Exclusions Public Extract CSV.
 *
 * CSV columns:
 * Classification, Name, Prefix, First, Middle, Last, Suffix,
 * Address 1, Address 2, Address 3, Address 4, City, State/Province, Country, Zip Code,
 * Open Data Flag, Blank (Deprecated), Unique Entity ID, Exclusion Program,
 * Excluding Agency, CT Code, Exclusion Type, Additional Comments,
 * Active Date, Termination Date, Record Status, Cross-Reference,
 * SAM Number, CAGE, NPI, Creation_Date
 */
class USGovSAMParser implements ParserInterface
{
    private LoggerInterface $logger;
    private int $errorCount = 0;

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
        $this->logger->info("Starting US SAM.gov exclusions parse", [
            'file' => $filePath,
            'source_id' => $sourceId,
        ]);

        $f = fopen($filePath, 'r');
        if ($f === false) {
            throw new \RuntimeException("Failed to open CSV: {$filePath}");
        }

        $header = fgetcsv($f);
        if ($header === false || count($header) < 20) {
            fclose($f);
            throw new \RuntimeException("Invalid CSV header in: {$filePath}");
        }

        $colMap = array_flip($header);
        $colCount = count($header);

        $entities = [];
        $errors = 0;
        $skipped = 0;
        $rowNum = 0;

        while (($data = fgetcsv($f)) !== false) {
            $rowNum++;

            if (count($data) !== $colCount) {
                $errors++;
                continue;
            }

            $row = array_combine($header, $data);

            $status = trim($row['Record Status'] ?? '');
            if ($status !== 'Active') {
                $skipped++;
                continue;
            }

            try {
                $entity = $this->parseRow($row, $sourceId, $rowNum);
                if ($entity !== null) {
                    $entities[] = $entity;
                }
            } catch (\Exception $e) {
                $errors++;
                if ($errors <= 10) {
                    $this->logger->error("Failed to parse SAM row", [
                        'row' => $rowNum,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        fclose($f);

        $this->logger->info("US SAM.gov parse complete", [
            'source_id' => $sourceId,
            'total_rows' => $rowNum,
            'entities' => count($entities),
            'skipped_inactive' => $skipped,
            'errors' => $errors,
        ]);

        $this->errorCount = $errors;

        return $entities;
    }

    private function parseRow(array $row, string $sourceId, int $rowNum): ?SanctionedEntity
    {
        $classification = trim($row['Classification'] ?? '');
        $name = trim($row['Name'] ?? '');
        $first = trim($row['First'] ?? '');
        $middle = trim($row['Middle'] ?? '');
        $last = trim($row['Last'] ?? '');

        // Build primary name
        if ($classification === 'Individual') {
            $parts = array_filter([$first, $middle, $last]);
            $primaryName = implode(' ', $parts);
            if ($primaryName === '' && $name !== '') {
                $primaryName = $name;
            }
        } else {
            $primaryName = $name;
            if ($primaryName === '') {
                $parts = array_filter([$first, $middle, $last]);
                $primaryName = implode(' ', $parts);
            }
        }

        if ($primaryName === '') return null;

        $entityType = match (strtolower($classification)) {
            'individual' => 'individual',
            'firm' => 'organization',
            'special entity designation' => 'organization',
            'vessel' => 'vessel',
            default => 'unknown',
        };

        // Generate stable ID from SAM Number or row-based
        $samNumber = trim($row['SAM Number'] ?? '');
        $sourceEntityId = $samNumber !== '' ? $samNumber : 'SAM_' . $rowNum;

        // Address
        $addresses = [];
        $addrParts = array_filter([
            trim($row['Address 1'] ?? ''),
            trim($row['Address 2'] ?? ''),
            trim($row['Address 3'] ?? ''),
            trim($row['Address 4'] ?? ''),
        ]);
        $city = trim($row['City'] ?? '');
        $state = trim($row['State / Province'] ?? $row['State/ Province'] ?? '');
        $country = trim($row['Country'] ?? '');
        $zip = trim($row['Zip Code'] ?? '');

        if ($city !== '') $addrParts[] = $city;
        if ($state !== '') $addrParts[] = $state;
        if ($zip !== '') $addrParts[] = $zip;

        if (!empty($addrParts) || $country !== '') {
            $addresses[] = [
                'full' => implode(', ', $addrParts),
                'city' => $city,
                'region' => $state,
                'postal' => $zip,
                'country' => $this->normalizeCountry($country),
            ];
        }

        // Programs
        $programs = [];
        $exclusionProgram = trim($row['Exclusion Program'] ?? '');
        if ($exclusionProgram !== '') {
            $programs[] = $exclusionProgram;
        }
        $exclusionType = trim($row['Exclusion Type'] ?? '');
        if ($exclusionType !== '') {
            $programs[] = $exclusionType;
        }

        // Identifiers
        $identifiers = [];
        $uei = trim($row['Unique Entity ID'] ?? '');
        if ($uei !== '') {
            $identifiers[] = [
                'type' => 'UEI',
                'value' => $uei,
                'country' => 'US',
                'valid' => true,
            ];
        }
        $cage = trim($row['CAGE'] ?? '');
        if ($cage !== '') {
            $identifiers[] = [
                'type' => 'CAGE',
                'value' => $cage,
                'country' => 'US',
                'valid' => true,
            ];
        }
        $npi = trim($row['NPI'] ?? '');
        if ($npi !== '' && $npi !== '0000000000') {
            $identifiers[] = [
                'type' => 'NPI',
                'value' => $npi,
                'country' => 'US',
                'valid' => true,
            ];
        }

        // Remarks
        $remarkParts = [];
        $agency = trim($row['Excluding Agency'] ?? '');
        if ($agency !== '') {
            $remarkParts[] = "Agency: {$agency}";
        }
        $comments = trim($row['Additional Comments'] ?? '');
        if ($comments !== '') {
            $remarkParts[] = $comments;
        }
        $crossRef = trim($row['Cross-Reference'] ?? '');
        if ($crossRef !== '') {
            $remarkParts[] = "Cross-ref: {$crossRef}";
        }
        $remarks = !empty($remarkParts) ? implode('; ', $remarkParts) : null;
        if ($remarks !== null && strlen($remarks) > 1000) {
            $remarks = mb_substr($remarks, 0, 1000);
        }

        // Dates
        $listedDate = $this->parseDate(trim($row['Active Date'] ?? ''));

        // Nationalities from country
        $nationalities = [];
        $countryCode = $this->normalizeCountry($country);
        if ($countryCode !== '') {
            $nationalities[] = $countryCode;
        }

        return new SanctionedEntity(
            sourceEntityId: $sourceEntityId,
            sourceId: $sourceId,
            entityType: $entityType,
            primaryName: $primaryName,
            aliases: [],
            dates: [],
            nationalities: $nationalities,
            identifiers: $identifiers,
            addresses: $addresses,
            programs: $programs,
            listedDate: $listedDate,
            remarks: $remarks,
            raw: [
                'classification' => $classification,
                'excluding_agency' => $agency,
                'exclusion_type' => $exclusionType,
                'termination_date' => trim($row['Termination Date'] ?? ''),
                'ct_code' => trim($row['CT Code'] ?? ''),
                'prefix' => trim($row['Prefix'] ?? ''),
            ]
        );
    }

    /**
     * Parse MM/DD/YYYY or "Indefinite" to ISO date.
     */
    private function parseDate(string $date): ?string
    {
        if ($date === '' || $date === 'Indefinite') return null;

        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $date, $m)) {
            return sprintf('%s-%s-%s', $m[3], $m[1], $m[2]);
        }

        return null;
    }

    /**
     * Normalize country names/codes to ISO-ish short codes.
     */
    private function normalizeCountry(string $country): string
    {
        $map = [
            'USA' => 'US', 'CAN' => 'CA', 'MEX' => 'MX',
            'GBR' => 'GB', 'DEU' => 'DE', 'FRA' => 'FR',
            'IND' => 'IN', 'CHN' => 'CN', 'JPN' => 'JP',
            'KOR' => 'KR', 'BRA' => 'BR', 'RUS' => 'RU',
            'AUS' => 'AU', 'ISR' => 'IL', 'ARE' => 'AE',
            'SAU' => 'SA', 'PAK' => 'PK', 'NGA' => 'NG',
            'COL' => 'CO', 'PER' => 'PE', 'ARG' => 'AR',
        ];

        $upper = strtoupper(trim($country));
        return $map[$upper] ?? $upper;
    }
}