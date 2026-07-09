<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;

class CanadaSEMAXMLParser implements ParserInterface
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
        $this->logger->info("Starting Canada SEMA XML parse", [
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
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'record') {
                try {
                    $xml = $reader->readOuterXml();
                    $node = @simplexml_load_string($xml);
                    if ($node === false) {
                        $errors++;
                        continue;
                    }

                    $entity = $this->parseRecord($node, $sourceId);
                    if ($entity !== null) {
                        $entities[] = $entity;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    if ($errors <= 10) {
                        $this->logger->error("Failed to parse Canada record", [
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
        }

        $reader->close();

        $this->logger->info("Canada SEMA parse complete", [
            'source_id' => $sourceId,
            'entities' => count($entities),
            'errors' => $errors
        ]);

        $this->errorCount = $errors;

        return $entities;
    }

    private function parseRecord(\SimpleXMLElement $node, string $sourceId): ?SanctionedEntity
    {
        $country = trim((string)($node->Country ?? ''));
        $lastName = trim((string)($node->LastName ?? ''));
        $givenName = trim((string)($node->GivenName ?? ''));
        $entityOrShip = trim((string)($node->EntityOrShip ?? ''));
        $title = trim((string)($node->Title ?? ''));
        $aliasesRaw = trim((string)($node->Aliases ?? ''));
        $dobRaw = trim((string)($node->DateOfBirthOrShipBuildDate ?? ''));
        $schedule = trim((string)($node->Schedule ?? ''));
        $item = trim((string)($node->Item ?? ''));
        $dateListing = trim((string)($node->DateOfListing ?? ''));

        // Build primary name
        $isEntity = $entityOrShip !== '';
        $primaryName = '';

        if ($isEntity) {
            $primaryName = $entityOrShip;
        } else {
            $parts = array_filter([$givenName, $lastName]);
            $primaryName = implode(' ', $parts);
        }

        if ($primaryName === '') return null;

        // Generate a stable unique ID from country + schedule + item
        // Clean country to get just the English name
        $countryClean = explode('/', $country)[0];
        $countryClean = trim($countryClean);
        $sourceEntityId = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $countryClean))
            . '_S' . preg_replace('/[^a-zA-Z0-9]/', '', $schedule)
            . '_I' . $item;

        // Entity type
        $entityType = $isEntity ? 'organization' : 'individual';

        // Aliases
        $aliases = [];
        if ($aliasesRaw !== '') {
            // Can be semicolon or comma separated
            $separator = str_contains($aliasesRaw, ';') ? ';' : ',';
            $aliasParts = explode($separator, $aliasesRaw);
            foreach ($aliasParts as $part) {
                $part = trim($part);
                if ($part !== '' && $part !== $primaryName) {
                    $aliases[] = [
                        'name' => $part,
                        'type' => 'aka',
                        'low_quality' => false,
                    ];
                }
            }
        }

        // DOB
        $dates = [];
        if ($dobRaw !== '' && !$isEntity) {
            $dates[] = [
                'type' => 'date_of_birth',
                'value' => $dobRaw,
                'circa' => false,
            ];
        }

        // Program from country
        $programs = [];
        if ($countryClean !== '') {
            $programs[] = $countryClean;
        }

        return new SanctionedEntity(
            sourceEntityId: $sourceEntityId,
            sourceId: $sourceId,
            entityType: $entityType,
            primaryName: $primaryName,
            aliases: $aliases,
            dates: $dates,
            nationalities: [],
            identifiers: [],
            addresses: [],
            programs: $programs,
            listedDate: $dateListing ?: null,
            remarks: $title ?: null,
            raw: [
                'country' => $country,
                'schedule' => $schedule,
                'item' => $item,
            ]
        );
    }
}