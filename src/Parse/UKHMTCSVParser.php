<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;

class UKHMTCSVParser implements ParserInterface
{
    private LoggerInterface $logger;
    private int $errorCount = 0;

    /**
     * CSV columns:
     * Name 6 (surname), Name 1 (first), Name 2, Name 3, Name 4, Name 5,
     * Title, Name Non-Latin Script, Non-Latin Script Type, Non-Latin Script Language,
     * DOB, Town of Birth, Country of Birth, Nationality, Passport Number, Passport Details,
     * National Identification Number, National Identification Details, Position,
     * Address 1-6, Post/Zip Code, Country, Other Information,
     * Group Type, Alias Type, Alias Quality, Regime, Listed On,
     * UK Sanctions List Date Designated, Last Updated, Group ID
     */

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
        $this->logger->info("Starting UK HMT CSV parse", [
            'file' => $filePath,
            'source_id' => $sourceId
        ]);

        $f = fopen($filePath, 'r');
        if ($f === false) {
            throw new \RuntimeException("Failed to open CSV: {$filePath}");
        }

        // First line is "Last Updated,27/01/2026"
        $dateLine = fgetcsv($f);

        // Second line is header
        $header = fgetcsv($f);
        if ($header === false || count($header) < 30) {
            fclose($f);
            throw new \RuntimeException("Invalid CSV header in: {$filePath}");
        }

        // Group slim row data by Group ID
        $groups = [];
        $errors = 0;
        $rowNum = 0;
        $colCount = count($header);

        $colMap = array_flip($header);

        while (($data = fgetcsv($f)) !== false) {
            $rowNum++;
            if (count($data) !== $colCount) {
                $errors++;
                continue;
            }

            $groupId = trim($data[$colMap['Group ID']] ?? '');
            if ($groupId === '') continue;

            // Extract only the fields we need to reduce memory
            $slim = [
                'name6' => trim($data[$colMap['Name 6']] ?? ''),
                'name1' => trim($data[$colMap['Name 1']] ?? ''),
                'name2' => trim($data[$colMap['Name 2']] ?? ''),
                'name3' => trim($data[$colMap['Name 3']] ?? ''),
                'name4' => trim($data[$colMap['Name 4']] ?? ''),
                'name5' => trim($data[$colMap['Name 5']] ?? ''),
                'non_latin' => trim($data[$colMap['Name Non-Latin Script']] ?? ''),
                'dob' => trim($data[$colMap['DOB']] ?? ''),
                'tob' => trim($data[$colMap['Town of Birth']] ?? ''),
                'cob' => trim($data[$colMap['Country of Birth']] ?? ''),
                'nationality' => trim($data[$colMap['Nationality']] ?? ''),
                'passport' => trim($data[$colMap['Passport Number']] ?? ''),
                'nat_id' => trim($data[$colMap['National Identification Number']] ?? ''),
                'position' => trim($data[$colMap['Position']] ?? ''),
                'addr1' => trim($data[$colMap['Address 1']] ?? ''),
                'addr2' => trim($data[$colMap['Address 2']] ?? ''),
                'addr3' => trim($data[$colMap['Address 3']] ?? ''),
                'addr4' => trim($data[$colMap['Address 4']] ?? ''),
                'addr5' => trim($data[$colMap['Address 5']] ?? ''),
                'addr6' => trim($data[$colMap['Address 6']] ?? ''),
                'postal' => trim($data[$colMap['Post/Zip Code']] ?? ''),
                'country' => trim($data[$colMap['Country']] ?? ''),
                'other_info' => trim($data[$colMap['Other Information']] ?? ''),
                'group_type' => trim($data[$colMap['Group Type']] ?? ''),
                'alias_type' => trim($data[$colMap['Alias Type']] ?? ''),
                'alias_quality' => trim($data[$colMap['Alias Quality']] ?? ''),
                'regime' => trim($data[$colMap['Regime']] ?? ''),
                'listed_on' => trim($data[$colMap['Listed On']] ?? ''),
                'designated' => trim($data[$colMap['UK Sanctions List Date Designated']] ?? ''),
            ];

            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [];
            }
            $groups[$groupId][] = $slim;
        }

        fclose($f);

        $this->logger->info("UK HMT rows grouped", [
            'total_rows' => $rowNum,
            'unique_groups' => count($groups),
            'errors' => $errors
        ]);

        $entities = [];
        foreach ($groups as $groupId => $rows) {
            try {
                $entity = $this->parseGroup($groupId, $rows, $sourceId);
                if ($entity !== null) {
                    $entities[] = $entity;
                }
            } catch (\Exception $e) {
                $errors++;
                if ($errors <= 10) {
                    $this->logger->error("Failed to parse UK HMT group", [
                        'group_id' => $groupId,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->logger->info("UK HMT parse complete", [
            'source_id' => $sourceId,
            'entities' => count($entities),
            'errors' => $errors
        ]);

        $this->errorCount = $errors;

        return $entities;
    }

    private function parseGroup(string $groupId, array $rows, string $sourceId): ?SanctionedEntity
    {
        $firstRow = $rows[0];

        $groupType = strtolower($firstRow['group_type']);
        $entityType = match (true) {
            str_contains($groupType, 'individual') => 'individual',
            str_contains($groupType, 'ship'), str_contains($groupType, 'vessel') => 'vessel',
            str_contains($groupType, 'entity'), str_contains($groupType, 'organisation') => 'organization',
            default => 'unknown',
        };

        $primaryName = '';
        $aliases = [];

        foreach ($rows as $row) {
            $fullName = $this->assembleName($row, $entityType);
            if ($fullName === '') continue;

            $aliasType = strtolower($row['alias_type']);
            $aliasQuality = strtolower($row['alias_quality']);
            $isPrimary = $aliasType === '' || str_contains($aliasType, 'primary');

            if ($isPrimary && $primaryName === '') {
                $primaryName = $fullName;
            } elseif ($fullName !== $primaryName) {
                $aliases[] = [
                    'name' => $fullName,
                    'type' => str_contains($aliasType, 'formerly') ? 'fka' : 'aka',
                    'low_quality' => str_contains($aliasQuality, 'low'),
                ];
            }

            $nonLatin = $row['non_latin'];
            if ($nonLatin !== '' && $nonLatin !== $primaryName) {
                $exists = false;
                foreach ($aliases as $a) {
                    if ($a['name'] === $nonLatin) { $exists = true; break; }
                }
                if (!$exists) {
                    $aliases[] = [
                        'name' => $nonLatin,
                        'type' => 'transliteration',
                        'low_quality' => false,
                    ];
                }
            }
        }

        if ($primaryName === '') return null;

        $dates = [];
        $seenDobs = [];
        foreach ($rows as $row) {
            $dob = $this->parseDOB($row['dob']);
            if ($dob !== null && !isset($seenDobs[$dob])) {
                $dates[] = ['type' => 'date_of_birth', 'value' => $dob, 'circa' => false];
                $seenDobs[$dob] = true;
            }
        }

        $nationalities = [];
        foreach ($rows as $row) {
            if ($row['nationality'] !== '' && !in_array($row['nationality'], $nationalities, true)) {
                $nationalities[] = $row['nationality'];
            }
        }

        $addresses = [];
        $seenAddrs = [];
        foreach ($rows as $row) {
            $addr = $this->parseAddress($row);
            if ($addr !== null) {
                $key = $addr['full'] . '|' . $addr['country'];
                if (!isset($seenAddrs[$key])) {
                    $addresses[] = $addr;
                    $seenAddrs[$key] = true;
                }
            }
        }

        $identifiers = [];
        $seenIds = [];
        foreach ($rows as $row) {
            if ($row['passport'] !== '' && !isset($seenIds["passport:{$row['passport']}"])) {
                $identifiers[] = ['type' => 'Passport', 'value' => $row['passport'], 'country' => '', 'valid' => true];
                $seenIds["passport:{$row['passport']}"] = true;
            }
            if ($row['nat_id'] !== '' && !isset($seenIds["natid:{$row['nat_id']}"])) {
                $identifiers[] = ['type' => 'National ID', 'value' => $row['nat_id'], 'country' => '', 'valid' => true];
                $seenIds["natid:{$row['nat_id']}"] = true;
            }
        }

        $programs = [];
        foreach ($rows as $row) {
            if ($row['regime'] !== '' && !in_array($row['regime'], $programs, true)) {
                $programs[] = $row['regime'];
            }
        }

        $remarks = null;
        foreach ($rows as $row) {
            if ($row['other_info'] !== '') {
                $remarks = $row['other_info'];
                if (strlen($remarks) > 1000) $remarks = mb_substr($remarks, 0, 1000);
                break;
            }
        }

        $pobStr = implode(', ', array_filter([$firstRow['tob'], $firstRow['cob']]));
        if ($pobStr !== '') {
            $remarks = ($remarks ? $remarks . '; ' : '') . "POB: {$pobStr}";
            if (strlen($remarks) > 1000) $remarks = mb_substr($remarks, 0, 1000);
        }

        $listedDate = $this->parseUKDate($firstRow['designated']);
        if ($listedDate === null) {
            $listedDate = $this->parseUKDate($firstRow['listed_on']);
        }

        return new SanctionedEntity(
            sourceEntityId: $groupId,
            sourceId: $sourceId,
            entityType: $entityType,
            primaryName: $primaryName,
            aliases: $aliases,
            dates: $dates,
            nationalities: $nationalities,
            identifiers: $identifiers,
            addresses: $addresses,
            programs: $programs,
            listedDate: $listedDate,
            remarks: $remarks,
            raw: [
                'group_type' => $firstRow['group_type'],
                'position' => $firstRow['position'],
            ]
        );
    }

    private function assembleName(array $row, string $entityType): string
    {
        if ($entityType === 'individual') {
            $parts = array_filter([$row['name1'], $row['name2'], $row['name3'], $row['name4'], $row['name5'], $row['name6']]);
            return implode(' ', $parts);
        }

        if ($row['name6'] !== '') {
            $parts = array_filter([$row['name6'], $row['name1'], $row['name2'], $row['name3'], $row['name4'], $row['name5']]);
            return implode(' ', $parts);
        }

        $parts = array_filter([$row['name1'], $row['name2'], $row['name3'], $row['name4'], $row['name5']]);
        return implode(' ', $parts);
    }

    private function parseAddress(array $row): ?array
    {
        $parts = array_filter([$row['addr1'], $row['addr2'], $row['addr3'], $row['addr4'], $row['addr5'], $row['addr6']]);
        $postal = $row['postal'];
        $country = $row['country'];

        if (empty($parts) && $postal === '' && $country === '') return null;

        if ($postal !== '') $parts[] = $postal;

        return [
            'full' => implode(', ', $parts),
            'city' => $row['addr2'] ?: ($row['addr1'] ?: ''),
            'region' => '',
            'postal' => $postal,
            'country' => $country,
        ];
    }

    /**
     * Parse UK date formats: "dd/mm/yyyy", "00/00/yyyy" (year only), "00/mm/yyyy" (month+year)
     */
    private function parseDOB(string $dob): ?string
    {
        if ($dob === '') return null;

        // "dd/mm/yyyy"
        $parts = explode('/', $dob);
        if (count($parts) !== 3) return null;

        [$day, $month, $year] = $parts;

        if (!is_numeric($year) || (int)$year < 1900) return null;

        $dayKnown = $day !== '00' && is_numeric($day) && (int)$day > 0;
        $monthKnown = $month !== '00' && is_numeric($month) && (int)$month > 0;

        if ($dayKnown && $monthKnown) {
            return sprintf('%s-%02d-%02d', $year, (int)$month, (int)$day);
        } elseif ($monthKnown) {
            return sprintf('%s-%02d', $year, (int)$month);
        } else {
            return $year;
        }
    }

    /**
     * Parse UK date format dd/mm/yyyy to ISO.
     */
    private function parseUKDate(string $date): ?string
    {
        if ($date === '') return null;

        $parts = explode('/', $date);
        if (count($parts) !== 3) return null;
        if (!is_numeric($parts[0]) || !is_numeric($parts[1]) || !is_numeric($parts[2])) return null;

        return sprintf('%s-%02d-%02d', $parts[2], (int)$parts[1], (int)$parts[0]);
    }
}