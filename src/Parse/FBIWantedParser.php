<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;

class FBIWantedParser implements ParserInterface
{
    private LoggerInterface $logger;

    /**
     * Subjects relevant for sanctions/compliance screening.
     * Entries with only excluded subjects are skipped.
     */
    private const INCLUDED_SUBJECTS = [
        'Ten Most Wanted Fugitives',
        'Most Wanted Terrorists',
        'Domestic Terrorism',
        'Seeking Information - Terrorism',
        'Cyber\'s Most Wanted',
        'Counterintelligence',
        'Criminal Enterprise Investigations',
        'China Threat',
        'Iran',
        'White-Collar Crime',
        'Violent Crime - Murders',
        'Additional Violent Crimes',
        'Crimes Against Children',
        'Human Trafficking',
        'Transnational Repression',
        'ECAP',
    ];

    /**
     * Month name -> number for DOB parsing.
     */
    private const MONTHS = [
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
        'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function parse(string $filePath, string $sourceId): array
    {
        $this->logger->info("Starting FBI Wanted parse", [
            'file' => $filePath,
            'source_id' => $sourceId
        ]);

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read file: {$filePath}");
        }

        $data = json_decode($raw, true);
        if ($data === null || !isset($data['items'])) {
            throw new \RuntimeException("Invalid JSON in: {$filePath}");
        }

        $entities = [];
        $skipped = 0;
        $errors = 0;

        foreach ($data['items'] as $item) {
            $subjects = $item['subjects'] ?? [];

            if (!$this->hasRelevantSubject($subjects)) {
                $skipped++;
                continue;
            }

            try {
                $entity = $this->parseItem($item, $sourceId);
                if ($entity !== null) {
                    $entities[] = $entity;
                }
            } catch (\Exception $e) {
                $errors++;
                if ($errors <= 10) {
                    $this->logger->error("Failed to parse FBI item", [
                        'title' => $item['title'] ?? '?',
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $this->logger->info("FBI Wanted parse complete", [
            'source_id' => $sourceId,
            'total_items' => count($data['items']),
            'entities' => count($entities),
            'skipped' => $skipped,
            'errors' => $errors
        ]);

        return $entities;
    }

    private function hasRelevantSubject(array $subjects): bool
    {
        if (empty($subjects)) return false;

        foreach ($subjects as $subject) {
            if (in_array($subject, self::INCLUDED_SUBJECTS, true)) {
                return true;
            }
        }

        return false;
    }

    private function parseItem(array $item, string $sourceId): ?SanctionedEntity
    {
        $uid = $item['uid'] ?? '';
        if ($uid === '') return null;

        $title = trim($item['title'] ?? '');
        if ($title === '') return null;

        // The title is often "FIRST LAST" in uppercase -- use it as primary name
        $primaryName = $this->normalizeName($title);

        // Aliases
        $aliases = [];
        foreach ($item['aliases'] ?? [] as $alias) {
            $alias = trim($alias, ' "\'');
            if ($alias !== '' && $alias !== $primaryName) {
                $aliases[] = [
                    'name' => $alias,
                    'type' => 'aka',
                    'low_quality' => false,
                ];
            }
        }

        // DOBs: "March 23, 1995" format
        $dates = [];
        foreach ($item['dates_of_birth_used'] ?? [] as $dobStr) {
            $parsed = $this->parseDOB($dobStr);
            if ($parsed !== null) {
                $dates[] = [
                    'type' => 'date_of_birth',
                    'value' => $parsed,
                    'circa' => false,
                ];
            }
        }

        // Nationalities
        $nationalities = [];
        $nat = trim($item['nationality'] ?? '');
        if ($nat !== '') {
            $nationalities[] = $nat;
        }

        // Place of birth
        $pob = trim($item['place_of_birth'] ?? '');
        $remarks = $pob !== '' ? "POB: {$pob}" : null;

        // Programs = subjects (filtered to relevant ones)
        $programs = [];
        foreach ($item['subjects'] ?? [] as $subject) {
            if (in_array($subject, self::INCLUDED_SUBJECTS, true)) {
                $programs[] = $subject;
            }
        }

        // Addresses from possible_countries/possible_states
        $addresses = [];
        foreach ($item['possible_countries'] ?? [] as $country) {
            $addresses[] = [
                'full' => $country,
                'city' => '',
                'region' => '',
                'postal' => '',
                'country' => $this->countryToCode($country),
            ];
        }

        // Warning/caution as additional remarks
        $warning = trim(strip_tags($item['warning_message'] ?? ''));
        if ($warning !== '') {
            $remarks = ($remarks ? $remarks . '; ' : '') . $warning;
        }

        $caution = trim(strip_tags($item['caution'] ?? ''));
        if ($caution !== '' && strlen($caution) <= 500) {
            $remarks = ($remarks ? $remarks . '; ' : '') . $caution;
        }

        if ($remarks !== null && strlen($remarks) > 1000) {
            $remarks = substr($remarks, 0, 1000);
        }

        // Sex to entity type -- FBI entries are always individuals
        $entityType = 'individual';

        return new SanctionedEntity(
            sourceEntityId: $uid,
            sourceId: $sourceId,
            entityType: $entityType,
            primaryName: $primaryName,
            aliases: $aliases,
            dates: $dates,
            nationalities: $nationalities,
            identifiers: [],
            addresses: $addresses,
            programs: $programs,
            listedDate: $this->parsePublicationDate($item['publication'] ?? ''),
            remarks: $remarks,
            raw: [
                'sex' => $item['sex'] ?? '',
                'race' => $item['race'] ?? '',
                'reward_text' => $item['reward_text'] ?? '',
                'url' => $item['url'] ?? '',
                'poster_classification' => $item['poster_classification'] ?? '',
            ]
        );
    }

    /**
     * Normalize FBI title names.
     * FBI uses "FIRST LAST" uppercase. Convert to title case.
     */
    private function normalizeName(string $name): string
    {
        // Remove location suffixes like " - LYNN, MASSACHUSETTS"
        $dashPos = strpos($name, ' - ');
        if ($dashPos !== false) {
            $beforeDash = substr($name, 0, $dashPos);
            // Only trim if the part after dash looks like a location (contains comma or state)
            $afterDash = substr($name, $dashPos + 3);
            if (str_contains($afterDash, ',') || preg_match('/^[A-Z\s]+$/', $afterDash)) {
                $name = $beforeDash;
            }
        }

        // If all uppercase, convert to title case
        if ($name === mb_strtoupper($name)) {
            $name = mb_convert_case($name, MB_CASE_TITLE);
        }

        return trim($name);
    }

    /**
     * Parse "March 23, 1995" -> "1995-03-23"
     * Also handles "1995" (year only) and "March 1995" (month+year).
     */
    private function parseDOB(string $dob): ?string
    {
        $dob = trim($dob);
        if ($dob === '') return null;

        // "March 23, 1995"
        if (preg_match('/^(\w+)\s+(\d{1,2}),?\s+(\d{4})$/', $dob, $m)) {
            $month = self::MONTHS[strtolower($m[1])] ?? null;
            if ($month !== null) {
                return sprintf('%s-%02d-%02d', $m[3], $month, (int)$m[2]);
            }
        }

        // "March 1995"
        if (preg_match('/^(\w+)\s+(\d{4})$/', $dob, $m)) {
            $month = self::MONTHS[strtolower($m[1])] ?? null;
            if ($month !== null) {
                return sprintf('%s-%02d', $m[2], $month);
            }
        }

        // "1995"
        if (preg_match('/^\d{4}$/', $dob)) {
            return $dob;
        }

        return null;
    }

    /**
     * Parse ISO publication date "2021-09-28T10:00:00" -> "2021-09-28"
     */
    private function parsePublicationDate(string $pub): ?string
    {
        if ($pub === '') return null;

        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $pub, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Map common country names to ISO codes.
     */
    private function countryToCode(string $country): string
    {
        $map = [
            'USA' => 'US', 'CAN' => 'CA', 'MEX' => 'MX',
            'United States' => 'US', 'Canada' => 'CA', 'Mexico' => 'MX',
            'Russia' => 'RU', 'China' => 'CN', 'Iran' => 'IR',
            'Cuba' => 'CU', 'Venezuela' => 'VE', 'Colombia' => 'CO',
            'Brazil' => 'BR', 'Nigeria' => 'NG', 'Ukraine' => 'UA',
        ];

        return $map[$country] ?? $country;
    }
}