<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;

class UNConsolidatedXMLParser implements ParserInterface
{
    private LoggerInterface $logger;
    private int $errorCount = 0;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $filePath  Path to the XML file
     * @param string $sourceId  Source identifier
     * @return SanctionedEntity[]
     */
    public function getErrorCount(): int
    {
        return $this->errorCount;
    }

    public function parse(string $filePath, string $sourceId): array
    {
        $this->logger->info("Starting UN consolidated XML parse", [
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
            if ($reader->nodeType !== \XMLReader::ELEMENT) continue;

            $name = $reader->localName;

            if ($name === 'INDIVIDUAL' || $name === 'ENTITY') {
                try {
                    $xml = $reader->readOuterXml();
                    $node = @simplexml_load_string($xml);
                    if ($node === false) {
                        $errors++;
                        continue;
                    }

                    $entity = $name === 'INDIVIDUAL'
                        ? $this->parseIndividual($node, $sourceId)
                        : $this->parseEntity($node, $sourceId);

                    if ($entity !== null) {
                        $entities[] = $entity;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    if ($errors <= 10) {
                        $this->logger->error("Failed to parse UN {$name}", [
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
        }

        $reader->close();

        $this->logger->info("UN parse complete", [
            'source_id' => $sourceId,
            'entities' => count($entities),
            'errors' => $errors
        ]);

        $this->errorCount = $errors;

        return $entities;
    }

    private function parseIndividual(\SimpleXMLElement $node, string $sourceId): ?SanctionedEntity
    {
        $dataId = (string)($node->DATAID ?? '');
        if ($dataId === '') return null;

        $firstName = trim((string)($node->FIRST_NAME ?? ''));
        $secondName = trim((string)($node->SECOND_NAME ?? ''));
        $thirdName = trim((string)($node->THIRD_NAME ?? ''));
        $fourthName = trim((string)($node->FOURTH_NAME ?? ''));

        $nameParts = array_filter([$firstName, $secondName, $thirdName, $fourthName], fn($p) => $p !== '');
        $primaryName = implode(' ', $nameParts);

        if ($primaryName === '') return null;

        // Aliases
        $aliases = [];
        foreach ($node->INDIVIDUAL_ALIAS ?? [] as $alias) {
            $aliasName = trim((string)($alias->ALIAS_NAME ?? ''));
            if ($aliasName === '') continue;

            $quality = strtolower(trim((string)($alias->QUALITY ?? '')));

            // UN often puts multiple aliases in one field separated by semicolons
            $aliasParts = explode(';', $aliasName);
            foreach ($aliasParts as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $aliases[] = [
                        'name' => $part,
                        'type' => 'aka',
                        'low_quality' => str_contains($quality, 'low'),
                    ];
                }
            }
        }

        // DOB
        $dates = [];
        foreach ($node->INDIVIDUAL_DATE_OF_BIRTH ?? [] as $dob) {
            $date = $this->extractDOB($dob);
            if ($date !== null) {
                $dates[] = $date;
            }
        }

        // Nationalities
        $nationalities = [];
        foreach ($node->NATIONALITY ?? [] as $nat) {
            $val = trim((string)($nat->VALUE ?? ''));
            if ($val !== '') {
                $nationalities[] = $val;
            }
        }

        // Addresses
        $addresses = [];
        foreach ($node->INDIVIDUAL_ADDRESS ?? [] as $addr) {
            $parsed = $this->extractAddress($addr);
            if ($parsed !== null) {
                $addresses[] = $parsed;
            }
        }

        // ID documents
        $identifiers = [];
        foreach ($node->INDIVIDUAL_DOCUMENT ?? [] as $doc) {
            $parsed = $this->extractDocument($doc);
            if ($parsed !== null) {
                $identifiers[] = $parsed;
            }
        }

        // Programs from UN_LIST_TYPE and REFERENCE_NUMBER
        $programs = [];
        $listType = trim((string)($node->UN_LIST_TYPE ?? ''));
        if ($listType !== '') {
            $programs[] = $listType;
        }

        $refNum = trim((string)($node->REFERENCE_NUMBER ?? ''));
        $listedOn = trim((string)($node->LISTED_ON ?? '')) ?: null;
        $comments = trim((string)($node->COMMENTS1 ?? '')) ?: null;

        // Place of birth as remarks
        $remarks = $comments;
        foreach ($node->INDIVIDUAL_PLACE_OF_BIRTH ?? [] as $pob) {
            $city = trim((string)($pob->CITY ?? ''));
            $country = trim((string)($pob->COUNTRY ?? ''));
            $pobStr = implode(', ', array_filter([$city, $country]));
            if ($pobStr !== '') {
                $remarks = ($remarks ? $remarks . '; ' : '') . "POB: {$pobStr}";
            }
        }

        return new SanctionedEntity(
            sourceEntityId: $dataId,
            sourceId: $sourceId,
            entityType: 'individual',
            primaryName: $primaryName,
            aliases: $aliases,
            dates: $dates,
            nationalities: $nationalities,
            identifiers: $identifiers,
            addresses: $addresses,
            programs: $programs,
            listedDate: $listedOn,
            remarks: $remarks,
            raw: ['reference_number' => $refNum]
        );
    }

    private function parseEntity(\SimpleXMLElement $node, string $sourceId): ?SanctionedEntity
    {
        $dataId = (string)($node->DATAID ?? '');
        if ($dataId === '') return null;

        $primaryName = trim((string)($node->FIRST_NAME ?? ''));
        if ($primaryName === '') return null;

        // Aliases
        $aliases = [];
        foreach ($node->ENTITY_ALIAS ?? [] as $alias) {
            $aliasName = trim((string)($alias->ALIAS_NAME ?? ''));
            if ($aliasName === '') continue;

            $quality = strtolower(trim((string)($alias->QUALITY ?? '')));

            $aliasParts = explode(';', $aliasName);
            foreach ($aliasParts as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $aliases[] = [
                        'name' => $part,
                        'type' => 'aka',
                        'low_quality' => str_contains($quality, 'low'),
                    ];
                }
            }
        }

        // Addresses
        $addresses = [];
        foreach ($node->ENTITY_ADDRESS ?? [] as $addr) {
            $parsed = $this->extractAddress($addr);
            if ($parsed !== null) {
                $addresses[] = $parsed;
            }
        }

        // Programs
        $programs = [];
        $listType = trim((string)($node->UN_LIST_TYPE ?? ''));
        if ($listType !== '') {
            $programs[] = $listType;
        }

        $refNum = trim((string)($node->REFERENCE_NUMBER ?? ''));
        $listedOn = trim((string)($node->LISTED_ON ?? '')) ?: null;
        $comments = trim((string)($node->COMMENTS1 ?? '')) ?: null;

        return new SanctionedEntity(
            sourceEntityId: $dataId,
            sourceId: $sourceId,
            entityType: 'organization',
            primaryName: $primaryName,
            aliases: $aliases,
            dates: [],
            nationalities: [],
            identifiers: [],
            addresses: $addresses,
            programs: $programs,
            listedDate: $listedOn,
            remarks: $comments,
            raw: ['reference_number' => $refNum]
        );
    }

    private function extractDOB(\SimpleXMLElement $dob): ?array
    {
        $year = trim((string)($dob->YEAR ?? ''));
        $dateStr = trim((string)($dob->DATE ?? ''));
        $typeOfDate = strtolower(trim((string)($dob->TYPE_OF_DATE ?? '')));

        if ($dateStr !== '') {
            return [
                'type' => 'date_of_birth',
                'value' => $dateStr,
                'circa' => str_contains($typeOfDate, 'approximate') || str_contains($typeOfDate, 'circa'),
            ];
        }

        if ($year !== '') {
            return [
                'type' => 'date_of_birth',
                'value' => $year,
                'circa' => str_contains($typeOfDate, 'approximate') || str_contains($typeOfDate, 'circa'),
            ];
        }

        return null;
    }

    private function extractAddress(\SimpleXMLElement $addr): ?array
    {
        $street = trim((string)($addr->STREET ?? ''));
        $city = trim((string)($addr->CITY ?? ''));
        $region = trim((string)($addr->STATE_PROVINCE ?? ''));
        $postal = trim((string)($addr->ZIP_CODE ?? ''));
        $country = trim((string)($addr->COUNTRY ?? ''));
        $note = trim((string)($addr->NOTE ?? ''));

        $parts = array_filter([$street, $city, $region, $postal, $country]);
        if (empty($parts) && $note === '') return null;

        return [
            'full' => implode(', ', $parts),
            'city' => $city,
            'region' => $region,
            'postal' => $postal,
            'country' => $country,
        ];
    }

    private function extractDocument(\SimpleXMLElement $doc): ?array
    {
        $type = trim((string)($doc->TYPE_OF_DOCUMENT ?? ''));
        $number = trim((string)($doc->NUMBER ?? ''));
        $country = trim((string)($doc->ISSUING_COUNTRY ?? ''));
        $note = trim((string)($doc->NOTE ?? ''));

        if ($number === '' && $note === '') return null;

        return [
            'type' => $type ?: 'unknown',
            'value' => $number ?: $note,
            'country' => $country,
            'valid' => true,
        ];
    }
}