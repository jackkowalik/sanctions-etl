<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;

class OFACAdvancedXMLParser implements ParserInterface
{
    private LoggerInterface $logger;
    private string $ns = 'https://sanctionslistservice.ofac.treas.gov/api/PublicationPreview/exports/ADVANCED_XML';

    // Reference lookups parsed from ReferenceValueSets
    private array $partySubTypes = [];       // ID -> PartyTypeID
    private array $partyTypes = [];          // ID -> name
    private array $aliasTypes = [];          // ID -> name
    private array $namePartTypes = [];       // ID -> name
    private array $featureTypes = [];        // ID -> name
    private array $idRegDocTypes = [];       // ID -> name
    private array $countries = [];           // ID -> ISO2
    private array $detailReferences = [];    // ID -> value
    private array $sanctionsPrograms = [];   // ID -> name
    private array $locations = [];           // ID -> parsed location array
    private array $idRegDocuments = [];      // IdentityID -> [{type, number, country, valid}, ...]
    private array $sanctionsEntries = [];    // ProfileID -> [program, ...]

    // PartyTypeID -> entity type
    private const PARTY_TYPE_MAP = [
        '1' => 'individual',
        '2' => 'organization',
        '3' => 'location',
        '4' => 'transport',
        '5' => 'organization',
    ];

    // PartySubTypeID fallback for transport subtypes
    private const TRANSPORT_SUBTYPES = [
        '1' => 'vessel',
        '2' => 'aircraft',
    ];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $filePath  Path to the XML file on disk
     * @param string $sourceId  Source identifier
     * @return SanctionedEntity[]
     */
    public function parse(string $filePath, string $sourceId): array
    {
        $this->logger->info("Starting OFAC advanced XML parse", [
            'file' => $filePath,
            'source_id' => $sourceId
        ]);

        // First pass: parse all reference data
        $this->parseReferenceData($filePath);

        // Second pass: parse distinct parties
        $entities = [];
        $count = 0;
        $errors = 0;

        $reader = new \XMLReader();
        $reader->open($filePath);

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'DistinctParty') {
                try {
                    $partyXml = $reader->readOuterXml();
                    $entity = $this->parseDistinctParty($partyXml, $sourceId);
                    if ($entity !== null) {
                        $entities[] = $entity;
                        $count++;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    if ($errors <= 10) {
                        $this->logger->error("Failed to parse DistinctParty", [
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
        }

        $reader->close();

        $this->logger->info("OFAC parse complete", [
            'source_id' => $sourceId,
            'entities' => $count,
            'errors' => $errors
        ]);

        return $entities;
    }

    private function parseReferenceData(string $filePath): void
    {
        $reader = new \XMLReader();
        $reader->open($filePath);

        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) continue;

            $name = $reader->localName;

            // Stop once we hit the actual data
            if ($name === 'DistinctParties') break;

            switch ($name) {
                case 'PartySubType':
                    $id = $reader->getAttribute('ID');
                    $partyTypeId = $reader->getAttribute('PartyTypeID');
                    $this->partySubTypes[$id] = $partyTypeId;
                    break;

                case 'PartyType':
                    $id = $reader->getAttribute('ID');
                    $reader->read(); // move to text
                    $this->partyTypes[$id] = trim($reader->value);
                    break;

                case 'AliasType':
                    $id = $reader->getAttribute('ID');
                    $reader->read();
                    $this->aliasTypes[$id] = trim($reader->value);
                    break;

                case 'NamePartType':
                    $id = $reader->getAttribute('ID');
                    $reader->read();
                    $this->namePartTypes[$id] = trim($reader->value);
                    break;

                case 'FeatureType':
                    $id = $reader->getAttribute('ID');
                    $reader->read();
                    $this->featureTypes[$id] = trim($reader->value);
                    break;

                case 'IDRegDocType':
                    $id = $reader->getAttribute('ID');
                    $reader->read();
                    $this->idRegDocTypes[$id] = trim($reader->value);
                    break;

                case 'DetailReference':
                    $id = $reader->getAttribute('ID');
                    $reader->read();
                    $this->detailReferences[$id] = trim($reader->value);
                    break;

                case 'SanctionsProgram':
                    $id = $reader->getAttribute('ID');
                    $reader->read();
                    $this->sanctionsPrograms[$id] = trim($reader->value);
                    break;

                case 'Country':
                    $id = $reader->getAttribute('ID');
                    $iso2 = $reader->getAttribute('ISO2');
                    if ($iso2) {
                        $this->countries[$id] = $iso2;
                    }
                    break;

                case 'Location':
                    $this->parseLocationRef($reader);
                    break;

                case 'IDRegDocument':
                    $this->parseIdRegDocumentRef($reader);
                    break;
            }
        }

        $reader->close();

        // SanctionsEntry may be outside ReferenceValueSets — do a second scan
        if (empty($this->sanctionsEntries)) {
            $this->parseSanctionsEntries($filePath);
        }

        // Count total ID docs across all identities
        $totalIdDocs = 0;
        foreach ($this->idRegDocuments as $docs) {
            $totalIdDocs += count($docs);
        }

        $this->logger->info("Reference data parsed", [
            'party_sub_types' => count($this->partySubTypes),
            'party_types' => count($this->partyTypes),
            'alias_types' => count($this->aliasTypes),
            'name_part_types' => count($this->namePartTypes),
            'feature_types' => count($this->featureTypes),
            'countries' => count($this->countries),
            'locations' => count($this->locations),
            'sanctions_programs' => count($this->sanctionsPrograms),
            'id_reg_doc_types' => count($this->idRegDocTypes),
            'id_reg_documents_identities' => count($this->idRegDocuments),
            'id_reg_documents_total' => $totalIdDocs,
            'sanctions_entries_profiles' => count($this->sanctionsEntries),
        ]);
    }

    private function parseLocationRef(\XMLReader $reader): void
    {
        $id = $reader->getAttribute('ID');
        if ($id === null) return;

        $xml = $reader->readOuterXml();
        $xml = str_replace(' xmlns="' . $this->ns . '"', '', $xml);
        $node = @simplexml_load_string($xml);
        if (!$node) return;

        $parts = [];

        // Locations use LocPart elements with LocPartTypeID
        foreach ($node->LocationPart ?? [] as $locPart) {
            $typeId = (string)($locPart['LocPartTypeID'] ?? '');
            foreach ($locPart->LocationPartValue ?? [] as $val) {
                $text = trim((string)($val->Value ?? ''));
                if ($text !== '') {
                    $parts[$typeId] = $text;
                }
            }
        }

        // Also check for country reference
        $countryId = '';
        foreach ($node->LocationCountry ?? [] as $lc) {
            $countryId = (string)($lc['CountryID'] ?? '');
        }

        // LocPartTypeID mapping from refs dump:
        // 1451 = ADDRESS1, 1452 = ADDRESS2, 1453 = ADDRESS3
        // 1454 = CITY, 1455 = STATE/PROVINCE, 1456 = POSTAL CODE
        // 1450 = REGION
        $this->locations[$id] = [
            'address1' => $parts['1451'] ?? '',
            'address2' => $parts['1452'] ?? '',
            'address3' => $parts['1453'] ?? '',
            'city' => $parts['1454'] ?? '',
            'region' => $parts['1455'] ?? ($parts['1450'] ?? ''),
            'postal' => $parts['1456'] ?? '',
            'country' => $this->countries[$countryId] ?? '',
            'full' => implode(', ', array_filter([
                $parts['1451'] ?? '',
                $parts['1452'] ?? '',
                $parts['1453'] ?? '',
                $parts['1454'] ?? '',
                $parts['1455'] ?? '',
                $parts['1456'] ?? '',
            ])),
        ];
    }

    private function parseSanctionsEntries(string $filePath): void
    {
        $reader = new \XMLReader();
        $reader->open($filePath);

        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'SanctionsEntry') {
                $profileId = $reader->getAttribute('ProfileID');
                if ($profileId === null || $profileId === '') continue;

                $xml = $reader->readOuterXml();
                $xml = str_replace(' xmlns="' . $this->ns . '"', '', $xml);
                $node = @simplexml_load_string($xml);
                if (!$node) continue;

                $programs = [];

                // Programs are in SanctionsMeasure > Comment
                foreach ($node->SanctionsMeasure ?? [] as $measure) {
                    $comment = trim((string)($measure->Comment ?? ''));
                    if ($comment !== '') {
                        $programs[] = $comment;
                    }
                }

                if (!empty($programs)) {
                    if (!isset($this->sanctionsEntries[$profileId])) {
                        $this->sanctionsEntries[$profileId] = [];
                    }
                    $this->sanctionsEntries[$profileId] = array_merge(
                        $this->sanctionsEntries[$profileId],
                        $programs
                    );
                }
            }
        }

        $reader->close();
    }

    private function parseIdRegDocumentRef(\XMLReader $reader): void
    {
        $id = $reader->getAttribute('ID');
        $typeId = $reader->getAttribute('IDRegDocTypeID');
        $identityId = $reader->getAttribute('IdentityID');
        $countryId = $reader->getAttribute('IssuedBy-CountryID');
        $validityId = $reader->getAttribute('ValidityID');

        if (!$identityId) return;

        $xml = $reader->readOuterXml();
        $xml = str_replace(' xmlns="' . $this->ns . '"', '', $xml);
        $node = @simplexml_load_string($xml);
        if (!$node) return;

        $number = trim((string)($node->IDRegistrationNo ?? ''));
        if ($number === '') return;

        $type = $this->idRegDocTypes[$typeId] ?? ($typeId ?: 'unknown');
        $country = $this->countries[$countryId] ?? '';
        $valid = $validityId !== '2';

        if (!isset($this->idRegDocuments[$identityId])) {
            $this->idRegDocuments[$identityId] = [];
        }

        $this->idRegDocuments[$identityId][] = [
            'type' => $type,
            'value' => $number,
            'country' => $country,
            'valid' => $valid,
        ];
    }

    private function parseSanctionsEntryRef(\XMLReader $reader): void
    {
        $xml = $reader->readOuterXml();
        $xml = str_replace(' xmlns="' . $this->ns . '"', '', $xml);
        $node = @simplexml_load_string($xml);
        if (!$node) return;

        $profileId = (string)($node['ProfileID'] ?? '');
        if ($profileId === '') return;

        $programs = [];
        foreach ($node->EntryEvent ?? [] as $event) {
            $progId = (string)($event['SanctionsProgramID'] ?? '');
            if ($progId !== '' && isset($this->sanctionsPrograms[$progId])) {
                $prog = $this->sanctionsPrograms[$progId];
                if ($prog !== '' && $prog !== 'Unknown') {
                    $programs[] = $prog;
                }
            }
        }

        if (!empty($programs)) {
            if (!isset($this->sanctionsEntries[$profileId])) {
                $this->sanctionsEntries[$profileId] = [];
            }
            $this->sanctionsEntries[$profileId] = array_merge(
                $this->sanctionsEntries[$profileId],
                $programs
            );
        }
    }

    private function parseDistinctParty(string $xml, string $sourceId): ?SanctionedEntity
    {
        $xml = str_replace(' xmlns="' . $this->ns . '"', '', $xml);
        $node = @simplexml_load_string($xml);
        if ($node === false) return null;

        $fixedRef = (string)$node['FixedRef'];
        if ($fixedRef === '') return null;

        $profile = $node->Profile;
        if (!$profile) return null;

        $profileId = (string)$profile['ID'];
        $partySubTypeId = (string)$profile['PartySubTypeID'];
        $entityType = $this->resolveEntityType($partySubTypeId);

        $identity = $profile->Identity;
        if (!$identity) return null;

        $identityId = (string)$identity['ID'];

        // Parse name part group mappings: groupId -> namePartTypeId
        $namePartGroupMap = [];
        if ($identity->NamePartGroups) {
            foreach ($identity->NamePartGroups->MasterNamePartGroup as $master) {
                foreach ($master->NamePartGroup as $group) {
                    $groupId = (string)$group['ID'];
                    $typeId = (string)$group['NamePartTypeID'];
                    $namePartGroupMap[$groupId] = $typeId;
                }
            }
        }

        // Parse aliases
        $primaryName = '';
        $aliases = [];

        foreach ($identity->Alias as $alias) {
            $aliasTypeId = (string)$alias['AliasTypeID'];
            $isPrimary = ((string)$alias['Primary']) === 'true';
            $isLowQuality = ((string)$alias['LowQuality']) === 'true';
            $aliasType = $this->resolveAliasType($aliasTypeId);

            foreach ($alias->DocumentedName as $docName) {
                $nameParts = $this->extractNameParts($docName, $namePartGroupMap, $entityType);
                $fullName = $nameParts['full_name'];

                if ($isPrimary && $primaryName === '') {
                    $primaryName = $fullName;
                } else {
                    $aliases[] = [
                        'name' => $fullName,
                        'type' => $aliasType,
                        'low_quality' => $isLowQuality,
                    ];
                }
            }
        }

        if ($primaryName === '') return null;

        // Parse features: DOB, nationality, locations
        $dates = [];
        $nationalities = [];
        $addresses = [];
        $remarks = '';

        foreach ($profile->Feature as $feature) {
            $featureTypeId = (string)$feature['FeatureTypeID'];
            $featureType = $this->resolveFeatureType($featureTypeId);

            foreach ($feature->FeatureVersion as $version) {
                switch ($featureType) {
                    case 'date_of_birth':
                        $date = $this->extractDate($version);
                        if ($date !== null) {
                            $dates[] = ['type' => 'date_of_birth', 'value' => $date, 'circa' => false];
                        }
                        break;

                    case 'nationality':
                    case 'citizenship':
                        $detailRef = (string)($version->VersionDetail['DetailReferenceID'] ?? '');
                        if ($detailRef !== '' && isset($this->detailReferences[$detailRef])) {
                            $nationalities[] = $this->detailReferences[$detailRef];
                        }
                        break;

                    case 'place_of_birth':
                        $locId = (string)($version->VersionLocation['LocationID'] ?? '');
                        if ($locId !== '' && isset($this->locations[$locId])) {
                            $loc = $this->locations[$locId];
                            $pob = $loc['full'] ?: ($loc['city'] ?: $loc['country']);
                            if ($pob !== '') {
                                $remarks .= "POB: {$pob}; ";
                            }
                        }
                        break;

                    case 'location':
                        $locId = (string)($version->VersionLocation['LocationID'] ?? '');
                        if ($locId !== '' && isset($this->locations[$locId])) {
                            $addresses[] = $this->locations[$locId];
                        }
                        break;
                }
            }
        }

        // ID documents from reference section (linked by IdentityID)
        $identifiers = $this->idRegDocuments[$identityId] ?? [];

        $nationalities = array_unique($nationalities);
        $programs = array_unique($this->sanctionsEntries[$profileId] ?? []);
        $remarks = rtrim($remarks, '; ') ?: null;

        return new SanctionedEntity(
            sourceEntityId: $fixedRef,
            sourceId: $sourceId,
            entityType: $entityType,
            primaryName: $primaryName,
            aliases: $aliases,
            dates: $dates,
            nationalities: $nationalities,
            identifiers: $identifiers,
            addresses: $addresses,
            programs: $programs,
            listedDate: null,
            remarks: $remarks,
            raw: ['profile_id' => $profileId, 'party_sub_type_id' => $partySubTypeId]
        );
    }

    private function extractNameParts(
        \SimpleXMLElement $docName,
        array $namePartGroupMap,
        string $entityType
    ): array {
        $surnames = [];
        $givenNames = [];
        $otherParts = [];

        foreach ($docName->DocumentedNamePart as $dnp) {
            $namePartValue = $dnp->NamePartValue;
            if (!$namePartValue) continue;

            $value = trim((string)$namePartValue);
            if ($value === '') continue;

            $groupId = (string)$namePartValue['NamePartGroupID'];
            $typeId = $namePartGroupMap[$groupId] ?? '';
            $typeName = $this->namePartTypes[$typeId] ?? '';
            $typeNameLower = strtolower($typeName);

            if (str_contains($typeNameLower, 'last') || str_contains($typeNameLower, 'surname')) {
                $surnames[] = $value;
            } elseif (str_contains($typeNameLower, 'first') || str_contains($typeNameLower, 'given')) {
                $givenNames[] = $value;
            } elseif (str_contains($typeNameLower, 'middle')) {
                $givenNames[] = $value;
            } elseif (str_contains($typeNameLower, 'patronymic') || str_contains($typeNameLower, 'matronymic')) {
                $givenNames[] = $value;
            } else {
                $otherParts[] = $value;
            }
        }

        if (!empty($otherParts) && empty($surnames) && empty($givenNames)) {
            $fullName = implode(' ', $otherParts);
        } else {
            $namePieces = array_merge($givenNames, $surnames);
            $fullName = implode(' ', $namePieces);
        }

        return ['full_name' => $fullName];
    }

    private function extractDate(\SimpleXMLElement $version): ?string
    {
        $datePeriod = $version->DatePeriod;
        if (!$datePeriod) return null;

        $start = $datePeriod->Start;
        if (!$start) return null;

        $from = $start->From;
        if (!$from) return null;

        $year = (string)($from->Year ?? '');
        $month = (string)($from->Month ?? '');
        $day = (string)($from->Day ?? '');

        if ($year === '') return null;

        $to = $start->To;
        if ($to) {
            $toYear = (string)($to->Year ?? '');
            if ($toYear !== '' && $toYear !== $year) {
                return $year; // approximate
            }
        }

        if ($month === '') return $year;
        if ($day === '') return sprintf('%s-%02d', $year, (int)$month);

        return sprintf('%s-%02d-%02d', $year, (int)$month, (int)$day);
    }

    private function resolveEntityType(string $partySubTypeId): string
    {
        // PartySubType -> PartyTypeID -> entity type
        // Special case: transport subtypes (vessel, aircraft)
        if ($partySubTypeId === '1') return 'vessel';
        if ($partySubTypeId === '2') return 'aircraft';

        $partyTypeId = $this->partySubTypes[$partySubTypeId] ?? '';
        if ($partyTypeId !== '') {
            $typeName = strtolower($this->partyTypes[$partyTypeId] ?? '');
            if (str_contains($typeName, 'individual')) return 'individual';
            if (str_contains($typeName, 'entity')) return 'organization';
            if (str_contains($typeName, 'transport')) return 'transport';

            return self::PARTY_TYPE_MAP[$partyTypeId] ?? 'unknown';
        }

        return 'unknown';
    }

    private function resolveAliasType(string $id): string
    {
        $val = strtoupper($this->aliasTypes[$id] ?? '');
        if (str_contains($val, 'A.K.A')) return 'aka';
        if (str_contains($val, 'F.K.A')) return 'fka';
        if (str_contains($val, 'N.K.A')) return 'nka';
        if ($val === 'NAME') return 'primary';
        return 'aka';
    }

    private function resolveFeatureType(string $id): string
    {
        $val = strtolower($this->featureTypes[$id] ?? '');

        if (str_contains($val, 'birthdate') || str_contains($val, 'date of birth')) return 'date_of_birth';
        if (str_contains($val, 'place of birth')) return 'place_of_birth';
        if (str_contains($val, 'nationality')) return 'nationality';
        if (str_contains($val, 'citizenship')) return 'citizenship';
        if (str_contains($val, 'location') || $val === 'address') return 'location';

        // FeatureTypeID 25 from the inspect output was a location
        if ($id === '25') return 'location';

        return $val ?: 'unknown';
    }
}