<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;

class EUConsolidatedXMLParser implements ParserInterface
{
    private LoggerInterface $logger;
    private int $errorCount = 0;
    private string $ns = 'http://eu.europa.ec/fpi/fsd/export';

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
        $this->logger->info("Starting EU consolidated XML parse", [
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
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'sanctionEntity') {
                try {
                    $logicalId = $reader->getAttribute('logicalId');
                    $xml = $reader->readOuterXml();
                    if ($xml === '' || $xml === false) {
                        $errors++;
                        if ($errors <= 10) {
                            $this->logger->error("Failed to read EU sanctionEntity node", [
                                'logical_id' => $logicalId
                            ]);
                        }
                        continue;
                    }
                    $xml = str_replace(' xmlns="' . $this->ns . '"', '', $xml);
                    $node = @simplexml_load_string($xml);
                    if ($node === false) {
                        $errors++;
                        if ($errors <= 10) {
                            $this->logger->error("Failed to parse EU sanctionEntity XML", [
                                'logical_id' => $logicalId
                            ]);
                        }
                        continue;
                    }

                    $entity = $this->parseSanctionEntity($node, $sourceId);
                    if ($entity !== null) {
                        $entities[] = $entity;
                    }
                } catch (\Exception $e) {
                    $errors++;
                    if ($errors <= 10) {
                        $this->logger->error("Failed to parse EU sanctionEntity", [
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
        }

        $reader->close();

        $this->logger->info("EU parse complete", [
            'source_id' => $sourceId,
            'entities' => count($entities),
            'errors' => $errors
        ]);

        $this->errorCount = $errors;

        return $entities;
    }

    private function parseSanctionEntity(\SimpleXMLElement $node, string $sourceId): ?SanctionedEntity
    {
        $logicalId = (string)$node['logicalId'];
        if ($logicalId === '') return null;

        // Entity type from subjectType
        $subjectCode = strtolower((string)($node->subjectType['code'] ?? ''));
        $entityType = match (true) {
            str_contains($subjectCode, 'person') => 'individual',
            str_contains($subjectCode, 'enterprise'), str_contains($subjectCode, 'entity') => 'organization',
            default => 'organization',
        };

        // Names from nameAlias elements
        $primaryName = '';
        $aliases = [];

        foreach ($node->nameAlias as $alias) {
            $wholeName = trim((string)($alias['wholeName'] ?? ''));
            $firstName = trim((string)($alias['firstName'] ?? ''));
            $middleName = trim((string)($alias['middleName'] ?? ''));
            $lastName = trim((string)($alias['lastName'] ?? ''));
            $isStrong = ((string)($alias['strong'] ?? 'true')) === 'true';
            $lang = (string)($alias['regulationLanguage'] ?? '');

            $name = $wholeName;
            if ($name === '') {
                $parts = array_filter([$firstName, $middleName, $lastName]);
                $name = implode(' ', $parts);
            }
            if ($name === '') continue;

            // Primary = first English strong alias
            if ($primaryName === '' && $lang === 'en' && $isStrong) {
                $primaryName = $name;
            } elseif ($primaryName === '' && $isStrong) {
                $primaryName = $name;
            } else {
                $aliases[] = [
                    'name' => $name,
                    'type' => 'aka',
                    'low_quality' => !$isStrong,
                ];
            }
        }

        if ($primaryName === '') return null;

        // Programs from regulation elements
        $programs = [];
        foreach ($node->regulation as $reg) {
            $programme = trim((string)($reg['programme'] ?? ''));
            if ($programme !== '') {
                $programs[] = $programme;
            }
        }
        $programs = array_unique($programs);

        // DOB from birthdate elements
        $dates = [];
        foreach ($node->birthdate as $bd) {
            $dateStr = trim((string)($bd['birthdate'] ?? ''));
            $year = trim((string)($bd['year'] ?? ''));
            $month = trim((string)($bd['monthOfYear'] ?? ''));
            $day = trim((string)($bd['dayOfMonth'] ?? ''));
            $circa = ((string)($bd['circa'] ?? 'false')) === 'true';

            if ($dateStr !== '') {
                $dates[] = ['type' => 'date_of_birth', 'value' => $dateStr, 'circa' => $circa];
            } elseif ($year !== '') {
                $val = $year;
                if ($month !== '' && $month !== '0') {
                    $val = sprintf('%s-%02d', $year, (int)$month);
                    if ($day !== '' && $day !== '0') {
                        $val = sprintf('%s-%02d-%02d', $year, (int)$month, (int)$day);
                    }
                }
                $dates[] = ['type' => 'date_of_birth', 'value' => $val, 'circa' => $circa];
            }
        }

        // Citizenships / nationalities
        $nationalities = [];
        foreach ($node->citizenship as $cit) {
            $iso = trim((string)($cit['countryIso2Code'] ?? ''));
            if ($iso !== '' && $iso !== '00') {
                $nationalities[] = $iso;
            }
        }
        $nationalities = array_unique($nationalities);

        // Addresses
        $addresses = [];
        foreach ($node->address as $addr) {
            $street = trim((string)($addr['street'] ?? ''));
            $city = trim((string)($addr['city'] ?? ''));
            $region = trim((string)($addr['region'] ?? ''));
            $postal = trim((string)($addr['zipCode'] ?? ''));
            $country = trim((string)($addr['countryIso2Code'] ?? ''));
            $countryDesc = trim((string)($addr['countryDescription'] ?? ''));

            if ($country === '00') $country = '';

            $parts = array_filter([$street, $city, $region, $postal, $countryDesc]);
            if (empty($parts)) continue;

            $addresses[] = [
                'full' => implode(', ', $parts),
                'city' => $city,
                'region' => $region,
                'postal' => $postal,
                'country' => $country,
            ];
        }

        // ID documents
        $identifiers = [];
        foreach ($node->identification as $id) {
            $number = trim((string)($id['number'] ?? ''));
            if ($number === '') continue;

            $type = trim((string)($id['identificationTypeDescription'] ?? ''));
            $typeCode = trim((string)($id['identificationTypeCode'] ?? ''));
            $country = trim((string)($id['countryIso2Code'] ?? ''));
            $isFalse = ((string)($id['knownFalse'] ?? 'false')) === 'true';

            if ($country === '00') $country = '';

            $identifiers[] = [
                'type' => $type ?: $typeCode ?: 'unknown',
                'value' => $number,
                'country' => $country,
                'valid' => !$isFalse,
            ];
        }

        // Remarks
        $remarks = trim((string)($node->remark ?? '')) ?: null;

        // Listed date from first regulation
        $listedDate = null;
        foreach ($node->regulation as $reg) {
            $pubDate = trim((string)($reg['publicationDate'] ?? ''));
            if ($pubDate !== '') {
                $listedDate = $pubDate;
                break;
            }
        }

        return new SanctionedEntity(
            sourceEntityId: $logicalId,
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
            raw: ['united_nation_id' => (string)($node['unitedNationId'] ?? '')]
        );
    }
}