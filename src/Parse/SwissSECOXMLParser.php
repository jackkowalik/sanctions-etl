<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;

class SwissSECOXMLParser implements ParserInterface
{
    private LoggerInterface $logger;

    /** place-id -> country ISO code mapping, built during parse */
    private array $places = [];

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function parse(string $filePath, string $sourceId): array
    {
        $this->logger->info("Starting Swiss SECO XML parse", [
            'file' => $filePath,
            'source_id' => $sourceId
        ]);

        $entities = [];
        $errors = 0;
        $skippedDupe = 0;
        $skippedDelisted = 0;
        $seenSsids = [];

        // First pass: collect place references
        $this->parsePlaces($filePath);

        // Second pass: parse targets
        $reader = new \XMLReader();
        if (!$reader->open($filePath)) {
            throw new \RuntimeException("Failed to open XML file: {$filePath}");
        }

        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) continue;
            if ($reader->localName !== 'target') continue;

            $ssid = $reader->getAttribute('ssid');
            if ($ssid === null) continue;

            if (isset($seenSsids[$ssid])) {
                $skippedDupe++;
                continue;
            }
            $seenSsids[$ssid] = true;

            try {
                $xml = $reader->readOuterXml();

                if ($this->isDelisted($xml)) {
                    $skippedDelisted++;
                    continue;
                }

                $entity = $this->parseTarget($xml, $sourceId, $ssid);
                if ($entity !== null) {
                    $entities[] = $entity;
                }
            } catch (\Exception $e) {
                $errors++;
                if ($errors <= 10) {
                    $this->logger->error("Failed to parse SECO target", [
                        'ssid' => $ssid,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        $reader->close();

        $this->logger->info("Swiss SECO parse complete", [
            'source_id' => $sourceId,
            'entities' => count($entities),
            'skipped_dupe' => $skippedDupe,
            'skipped_delisted' => $skippedDelisted,
            'errors' => $errors,
            'places_loaded' => count($this->places)
        ]);

        return $entities;
    }

    /**
     * First pass: collect all place-id -> country ISO mappings.
     */
    private function parsePlaces(string $filePath): void
    {
        $this->places = [];
        $reader = new \XMLReader();
        if (!$reader->open($filePath)) return;

        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT) continue;
            if ($reader->localName !== 'place') continue;

            $placeId = $reader->getAttribute('ssid');
            if ($placeId === null) continue;

            $xml = $reader->readOuterXml();
            $node = @simplexml_load_string($xml);
            if (!$node) continue;

            $country = (string)($node->country['iso-code'] ?? '');
            if ($country !== '') {
                $this->places[$placeId] = strtoupper($country);
            }
        }

        $reader->close();

        $this->logger->info("SECO places loaded", ['count' => count($this->places)]);
    }

    /**
     * Check if a target XML block contains a de-listing modification
     * as its most recent (last) modification.
     */
    private function isDelisted(string $xml): bool
    {
        // The last modification-type in the target determines current status
        $lastType = '';
        if (preg_match_all('/modification-type="([^"]+)"/', $xml, $matches)) {
            $lastType = end($matches[1]);
        }
        return $lastType === 'de-listed';
    }

    private function parseTarget(string $xml, string $sourceId, string $ssid): ?SanctionedEntity
    {
        $node = @simplexml_load_string($xml);
        if ($node === false) return null;

        // Determine if individual or entity
        $individual = $node->individual;
        $entityNode = $node->entity;

        if ($individual && $individual->count() > 0) {
            return $this->parseIndividual($individual, $node, $sourceId, $ssid);
        }

        if ($entityNode && $entityNode->count() > 0) {
            return $this->parseEntity($entityNode, $node, $sourceId, $ssid);
        }

        return null;
    }

    private function parseIndividual(\SimpleXMLElement $individual, \SimpleXMLElement $target, string $sourceId, string $ssid): ?SanctionedEntity
    {
        $identity = $this->getMainIdentity($individual);
        if ($identity === null) return null;

        $names = $this->extractNames($identity);
        if ($names['primary'] === '') return null;

        $dates = [];
        foreach ($identity->{'day-month-year'} as $dmy) {
            $date = $this->parseDayMonthYear($dmy);
            if ($date !== null) {
                $dates[] = ['type' => 'date_of_birth', 'value' => $date, 'circa' => false];
            }
        }

        $nationalities = [];
        foreach ($identity->nationality as $nat) {
            $iso = (string)($nat->country['iso-code'] ?? '');
            if ($iso !== '') {
                $nationalities[] = strtoupper($iso);
            }
        }

        $addresses = $this->extractAddresses($identity);
        $programs = $this->extractPrograms($target);
        $remarks = $this->extractRemarks($individual);

        $pob = $this->extractPlaceOfBirth($identity);
        if ($pob !== '') {
            $remarks = ($remarks ? $remarks . '; ' : '') . "POB: {$pob}";
        }

        if ($remarks !== null && strlen($remarks) > 1000) {
            $remarks = substr($remarks, 0, 1000);
        }

        return new SanctionedEntity(
            sourceEntityId: $ssid,
            sourceId: $sourceId,
            entityType: 'individual',
            primaryName: $names['primary'],
            aliases: $names['aliases'],
            dates: $dates,
            nationalities: array_unique($nationalities),
            identifiers: $this->extractIdentifiers($individual),
            addresses: $addresses,
            programs: $programs,
            listedDate: $this->extractListedDate($target),
            remarks: $remarks,
            raw: ['ssid' => $ssid]
        );
    }

    private function parseEntity(\SimpleXMLElement $entityNode, \SimpleXMLElement $target, string $sourceId, string $ssid): ?SanctionedEntity
    {
        $identity = $this->getMainIdentity($entityNode);
        if ($identity === null) return null;

        $names = $this->extractNames($identity);
        if ($names['primary'] === '') return null;

        $addresses = $this->extractAddresses($identity);
        $programs = $this->extractPrograms($target);
        $remarks = $this->extractRemarks($entityNode);

        if ($remarks !== null && strlen($remarks) > 1000) {
            $remarks = substr($remarks, 0, 1000);
        }

        $dates = [];
        foreach ($identity->{'day-month-year'} as $dmy) {
            $date = $this->parseDayMonthYear($dmy);
            if ($date !== null) {
                $dates[] = ['type' => 'registration', 'value' => $date, 'circa' => false];
            }
        }

        return new SanctionedEntity(
            sourceEntityId: $ssid,
            sourceId: $sourceId,
            entityType: 'organization',
            primaryName: $names['primary'],
            aliases: $names['aliases'],
            dates: $dates,
            nationalities: [],
            identifiers: $this->extractIdentifiers($entityNode),
            addresses: $addresses,
            programs: $programs,
            listedDate: $this->extractListedDate($target),
            remarks: $remarks,
            raw: ['ssid' => $ssid]
        );
    }

    private function getMainIdentity(\SimpleXMLElement $node): ?\SimpleXMLElement
    {
        foreach ($node->identity as $identity) {
            if ((string)($identity['main'] ?? '') === 'true') {
                return $identity;
            }
        }
        // Fallback to first identity
        return $node->identity[0] ?? null;
    }

    private function extractNames(\SimpleXMLElement $identity): array
    {
        $primary = '';
        $aliases = [];

        foreach ($identity->name as $nameNode) {
            $nameType = (string)($nameNode['name-type'] ?? '');
            $fullName = $this->assembleNameParts($nameNode);

            if ($fullName === '') continue;

            if ($nameType === 'primary-name' && $primary === '') {
                $primary = $fullName;

                // Spelling variants of primary name become aliases
                foreach ($nameNode->{'name-part'} as $part) {
                    foreach ($part->{'spelling-variant'} as $variant) {
                        $variantName = trim((string)$variant);
                        if ($variantName !== '' && $variantName !== $primary) {
                            $aliases[] = [
                                'name' => $variantName,
                                'type' => 'transliteration',
                                'low_quality' => false,
                            ];
                        }
                    }
                }
            } else {
                $aliases[] = [
                    'name' => $fullName,
                    'type' => $nameType === 'alias' ? 'aka' : 'aka',
                    'low_quality' => ((string)($nameNode['quality'] ?? '')) === 'low',
                ];

                // Spelling variants of aliases too
                foreach ($nameNode->{'name-part'} as $part) {
                    foreach ($part->{'spelling-variant'} as $variant) {
                        $variantName = trim((string)$variant);
                        if ($variantName !== '' && $variantName !== $fullName) {
                            $aliases[] = [
                                'name' => $variantName,
                                'type' => 'transliteration',
                                'low_quality' => false,
                            ];
                        }
                    }
                }
            }
        }

        return ['primary' => $primary, 'aliases' => $aliases];
    }

    private function assembleNameParts(\SimpleXMLElement $nameNode): string
    {
        $parts = [];
        $ordered = [];

        foreach ($nameNode->{'name-part'} as $part) {
            $order = (int)($part['order'] ?? 0);
            $type = (string)($part['name-part-type'] ?? '');
            $value = trim((string)($part->value ?? ''));

            if ($value === '') continue;

            $ordered[$order] = ['type' => $type, 'value' => $value];
        }

        ksort($ordered);

        // For individuals: given-name + father-name + family-name
        // For entities: whole-name
        $givenNames = [];
        $familyNames = [];
        $wholeNames = [];

        foreach ($ordered as $part) {
            switch ($part['type']) {
                case 'given-name':
                case 'father-name':
                    $givenNames[] = $part['value'];
                    break;
                case 'family-name':
                    $familyNames[] = $part['value'];
                    break;
                case 'whole-name':
                    $wholeNames[] = $part['value'];
                    break;
                default:
                    $givenNames[] = $part['value'];
                    break;
            }
        }

        if (!empty($wholeNames)) {
            return implode(' ', $wholeNames);
        }

        return implode(' ', array_merge($givenNames, $familyNames));
    }

    private function extractAddresses(\SimpleXMLElement $identity): array
    {
        $addresses = [];

        foreach ($identity->address as $addr) {
            $details = trim((string)($addr->{'address-details'} ?? ''));
            $zip = trim((string)($addr->{'zip-code'} ?? ''));
            $co = trim((string)($addr->{'c-o'} ?? ''));

            $placeId = (string)($addr['place-id'] ?? '');
            $country = $this->places[$placeId] ?? '';

            $fullParts = array_filter([$co, $details, $zip]);
            if (empty($fullParts) && $country === '') continue;

            $addresses[] = [
                'full' => implode(', ', $fullParts),
                'city' => '',
                'region' => '',
                'postal' => $zip,
                'country' => $country,
            ];
        }

        return $addresses;
    }

    private function extractPrograms(\SimpleXMLElement $target): array
    {
        $programs = [];
        foreach ($target->{'sanctions-set-id'} as $setId) {
            $id = trim((string)$setId);
            if ($id !== '') {
                $programs[] = $id;
            }
        }
        return array_unique($programs);
    }

    private function extractRemarks(\SimpleXMLElement $node): ?string
    {
        $parts = [];
        foreach ($node->justification as $just) {
            $text = trim(strip_tags((string)$just));
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return !empty($parts) ? implode('; ', $parts) : null;
    }

    private function extractIdentifiers(\SimpleXMLElement $node): array
    {
        $identifiers = [];

        foreach ($node->{'other-information'} as $info) {
            $text = trim((string)$info);
            if ($text === '') continue;

            // Try to extract structured info like "Registration number: 100230590"
            if (preg_match('/^(Registration number|OKPO|Phone|Fax|Email|Web|Website):\s*(.+)$/i', $text, $m)) {
                $type = trim($m[1]);
                $value = trim($m[2]);

                if (in_array(strtolower($type), ['registration number', 'okpo'])) {
                    $identifiers[] = [
                        'type' => $type,
                        'value' => $value,
                        'country' => 'CH',
                        'valid' => true,
                    ];
                }
            }
        }

        return $identifiers;
    }

    private function extractPlaceOfBirth(\SimpleXMLElement $identity): string
    {
        foreach ($identity->{'place-of-birth'} as $pob) {
            $placeId = (string)($pob['place-id'] ?? '');
            if ($placeId !== '' && isset($this->places[$placeId])) {
                return $this->places[$placeId];
            }
        }
        return '';
    }

    private function extractListedDate(\SimpleXMLElement $target): ?string
    {
        foreach ($target->modification as $mod) {
            $type = (string)($mod['modification-type'] ?? '');
            if ($type === 'listed') {
                $date = (string)($mod['enactment-date'] ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    return $date;
                }
            }
        }
        return null;
    }

    private function parseDayMonthYear(\SimpleXMLElement $dmy): ?string
    {
        $year = (string)($dmy['year'] ?? '');
        $month = (string)($dmy['month'] ?? '');
        $day = (string)($dmy['day'] ?? '');

        if ($year === '') return null;

        if ($month === '') return $year;
        if ($day === '') return sprintf('%s-%02d', $year, (int)$month);

        return sprintf('%s-%02d-%02d', $year, (int)$month, (int)$day);
    }
}