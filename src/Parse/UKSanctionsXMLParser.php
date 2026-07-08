<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;

class UKSanctionsXMLParser implements ParserInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function parse(string $filePath, string $sourceId): array
    {
        $this->logger->info("Starting UK sanctions XML parse", [
            'file' => $filePath,
            'source_id' => $sourceId
        ]);

        $entities = [];
        $errors = 0;

        $reader = new \XMLReader();
        if (!$reader->open($filePath)) {
            throw new \RuntimeException("Failed to open XML file: {$filePath}");
        }

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'Designation') {
                try {
                    $xml = $reader->readOuterXml();
                    $node = @simplexml_load_string($xml);
                    if ($node === false) {
                        $errors++;
                        continue;
                    }

                    $entity = $this->parseDesignation($node, $sourceId);
                    if ($entity !== null) {
                        $entities[] = $entity;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    if ($errors <= 10) {
                        $this->logger->error("Failed to parse UK Designation", [
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
        }

        $reader->close();

        $this->logger->info("UK parse complete", [
            'source_id' => $sourceId,
            'entities' => count($entities),
            'errors' => $errors
        ]);

        return $entities;
    }

    private function parseDesignation(\SimpleXMLElement $node, string $sourceId): ?SanctionedEntity
    {
        $uniqueId = trim((string)($node->UniqueID ?? ''));
        if ($uniqueId === '') return null;

        // Entity type
        $typeStr = strtolower(trim((string)($node->IndividualEntityShip ?? '')));
        $entityType = match (true) {
            str_contains($typeStr, 'individual') => 'individual',
            str_contains($typeStr, 'ship') => 'vessel',
            default => 'organization',
        };

        // Names
        $primaryName = '';
        $aliases = [];

        foreach ($node->Names->Name ?? [] as $nameNode) {
            $nameType = strtolower(trim((string)($nameNode->NameType ?? '')));

            // Name parts: Name1=first, Name2=middle, Name3=?, Name4=?, Name5=?, Name6=surname/whole
            $name1 = trim((string)($nameNode->Name1 ?? ''));
            $name2 = trim((string)($nameNode->Name2 ?? ''));
            $name3 = trim((string)($nameNode->Name3 ?? ''));
            $name4 = trim((string)($nameNode->Name4 ?? ''));
            $name5 = trim((string)($nameNode->Name5 ?? ''));
            $name6 = trim((string)($nameNode->Name6 ?? ''));

            // For individuals: Name1=given, Name6=surname -> "Given Surname"
            // For entities: Name6 is often the whole name
            $parts = array_filter([$name1, $name2, $name3, $name4, $name5, $name6]);
            $fullName = implode(' ', $parts);

            if ($fullName === '') continue;

            if (str_contains($nameType, 'primary') && $primaryName === '') {
                $primaryName = $fullName;
            } else {
                $isLowQuality = str_contains($nameType, 'low quality') || str_contains($nameType, 'formerly');
                $aliases[] = [
                    'name' => $fullName,
                    'type' => str_contains($nameType, 'formerly') ? 'fka' : 'aka',
                    'low_quality' => $isLowQuality,
                ];
            }
        }

        // Non-latin name aliases
        foreach ($node->NonLatinNames->NonLatinName ?? [] as $nlName) {
            $script = trim((string)($nlName->NameNonLatinScript ?? ''));
            if ($script !== '') {
                $aliases[] = [
                    'name' => $script,
                    'type' => 'aka',
                    'low_quality' => false,
                ];
            }
        }

        if ($primaryName === '') return null;

        // Program from RegimeName
        $programs = [];
        $regimeName = trim((string)($node->RegimeName ?? ''));
        if ($regimeName !== '') {
            // Extract short program name from regime
            // e.g. "The Russia (Sanctions) (EU Exit) Regulations 2019" -> "Russia"
            if (preg_match('/^The\s+(.+?)\s*\(Sanctions\)/i', $regimeName, $m)) {
                $programs[] = $m[1];
            } else {
                $programs[] = $regimeName;
            }
        }

        // Listed date
        $listedDate = $this->parseUKDate(trim((string)($node->DateDesignated ?? '')));

        // Individual-specific fields
        $dates = [];
        $nationalities = [];
        $identifiers = [];
        $addresses = [];
        $remarks = null;

        $individual = $node->IndividualDetails->Individual ?? null;
        if ($individual) {
            // DOBs
            foreach ($individual->DOBs->DOB ?? [] as $dob) {
                $date = $this->parseDOB(trim((string)$dob));
                if ($date !== null) {
                    $dates[] = $date;
                }
            }

            // Nationalities
            foreach ($individual->Nationalities->Nationality ?? [] as $nat) {
                $val = trim((string)$nat);
                if ($val !== '') {
                    $nationalities[] = $val;
                }
            }

            // Passports
            foreach ($individual->PassportDetails->Passport ?? [] as $passport) {
                $number = trim((string)($passport->PassportNumber ?? ''));
                if ($number !== '') {
                    $identifiers[] = [
                        'type' => 'Passport',
                        'value' => $number,
                        'country' => '',
                        'valid' => true,
                    ];
                }
            }

            // National ID numbers
            foreach ($individual->NationalIdentifierDetails->NationalIdentifier ?? [] as $nid) {
                $number = trim((string)($nid->NationalIdentifierNumber ?? ''));
                if ($number !== '') {
                    $identifiers[] = [
                        'type' => 'National ID',
                        'value' => $number,
                        'country' => '',
                        'valid' => true,
                    ];
                }
            }

            // Birth details as remarks
            $towns = [];
            foreach ($individual->BirthDetails->BirthDetail ?? [] as $bd) {
                $town = trim((string)($bd->TownOfBirth ?? ''));
                $country = trim((string)($bd->CountryOfBirth ?? ''));
                $pob = implode(', ', array_filter([$town, $country]));
                if ($pob !== '') $towns[] = $pob;
            }
            if (!empty($towns)) {
                $remarks = 'POB: ' . implode('; ', array_unique($towns));
            }
        }

        // Addresses
        foreach ($node->Addresses->Address ?? [] as $addr) {
            $line1 = trim((string)($addr->AddressLine1 ?? ''));
            $line2 = trim((string)($addr->AddressLine2 ?? ''));
            $line3 = trim((string)($addr->AddressLine3 ?? ''));
            $line4 = trim((string)($addr->AddressLine4 ?? ''));
            $line5 = trim((string)($addr->AddressLine5 ?? ''));
            $line6 = trim((string)($addr->AddressLine6 ?? ''));
            $postal = trim((string)($addr->PostCode ?? ''));
            $country = trim((string)($addr->AddressCountry ?? ''));

            $parts = array_filter([$line1, $line2, $line3, $line4, $line5, $line6, $postal]);
            if (empty($parts) && $country === '') continue;

            $addresses[] = [
                'full' => implode(', ', array_filter([...$parts, $country])),
                'city' => $line3 ?: $line4 ?: '',
                'region' => '',
                'postal' => $postal,
                'country' => $country,
            ];
        }

        // Other info as additional remarks
        $otherInfo = trim((string)($node->OtherInformation ?? ''));
        if ($otherInfo !== '') {
            $remarks = ($remarks ? $remarks . '; ' : '') . $otherInfo;
            if (strlen($remarks) > 1000) {
                $remarks = mb_substr($remarks, 0, 1000);
            }
        }

        $nationalities = array_unique($nationalities);

        return new SanctionedEntity(
            sourceEntityId: $uniqueId,
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
                'ofsi_group_id' => trim((string)($node->OFSIGroupID ?? '')),
                'un_reference' => trim((string)($node->UNReferenceNumber ?? '')),
                'designation_source' => trim((string)($node->DesignationSource ?? '')),
            ]
        );
    }

    /**
     * Parse UK DOB format: dd/mm/yyyy, dd/mm/yyyy, or partial like "dd/mm/1945"
     * "dd" and "mm" are literal strings meaning unknown day/month
     */
    private function parseDOB(string $dob): ?array
    {
        if ($dob === '') return null;

        $parts = explode('/', $dob);
        if (count($parts) !== 3) return null;

        [$day, $month, $year] = $parts;

        if (!is_numeric($year)) return null;

        $dayKnown = $day !== 'dd' && is_numeric($day);
        $monthKnown = $month !== 'mm' && is_numeric($month);

        if ($dayKnown && $monthKnown) {
            $value = sprintf('%s-%02d-%02d', $year, (int)$month, (int)$day);
        } elseif ($monthKnown) {
            $value = sprintf('%s-%02d', $year, (int)$month);
        } else {
            $value = $year;
        }

        return [
            'type' => 'date_of_birth',
            'value' => $value,
            'circa' => false,
        ];
    }

    /**
     * Parse UK date format dd/mm/yyyy to ISO
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