<?php

namespace SanctionsEtl\Data;

class SanctionedEntity
{
    private string $sourceEntityId;
    private string $sourceId;
    private string $entityType;
    private string $primaryName;
    private array $aliases;
    private array $dates;
    private array $nationalities;
    private array $identifiers;
    private array $addresses;
    private array $programs;
    private ?string $listedDate;
    private ?string $remarks;
    private array $raw;

    /**
     * @param string   $sourceEntityId  unique id from the source list (OFAC UID, UN reference number, etc.)
     * @param string   $sourceId        which list this came from ('ofac_sdn', 'un_consolidated', etc.)
     * @param string   $entityType      'individual', 'organization', 'vessel', 'aircraft'
     * @param string   $primaryName     primary/official name
     * @param array    $aliases         [{name: string, type: 'aka'|'fka'|'spelling_variant'|'transliteration'}, ...]
     * @param array    $dates           [{type: 'date_of_birth'|'registration', value: string, circa: bool}, ...]
     * @param array    $nationalities   ['US', 'RU', ...]
     * @param array    $identifiers     [{type: 'passport'|'national_id'|'tax_id'|'ssn', value: string, country: ?string}, ...]
     * @param array    $addresses       [{street: ?string, city: ?string, region: ?string, country: ?string, postal: ?string, full: string}, ...]
     * @param array    $programs        ['SDGT', 'IRAN', 'UKRAINE-EO13662', ...] - sanctions program references
     * @param ?string  $listedDate      When the entity was added to the source list
     * @param ?string  $remarks         Free-text notes from the source
     * @param array    $raw             Original unparsed fields for audit/debugging
     */
    public function __construct(
        string $sourceEntityId,
        string $sourceId,
        string $entityType,
        string $primaryName,
        array $aliases = [],
        array $dates = [],
        array $nationalities = [],
        array $identifiers = [],
        array $addresses = [],
        array $programs = [],
        ?string $listedDate = null,
        ?string $remarks = null,
        array $raw = []
    ) {
        $this->sourceEntityId = $sourceEntityId;
        $this->sourceId = $sourceId;
        $this->entityType = $entityType;
        $this->primaryName = $primaryName;
        $this->aliases = $aliases;
        $this->dates = $dates;
        $this->nationalities = $nationalities;
        $this->identifiers = $identifiers;
        $this->addresses = $addresses;
        $this->programs = $programs;
        $this->listedDate = $listedDate;
        $this->remarks = $remarks;
        $this->raw = $raw;
    }

    public function getSourceEntityId(): string { return $this->sourceEntityId; }
    public function getSourceId(): string { return $this->sourceId; }
    public function getEntityType(): string { return $this->entityType; }
    public function getPrimaryName(): string { return $this->primaryName; }
    public function getAliases(): array { return $this->aliases; }
    public function getDates(): array { return $this->dates; }
    public function getNationalities(): array { return $this->nationalities; }
    public function getIdentifiers(): array { return $this->identifiers; }
    public function getAddresses(): array { return $this->addresses; }
    public function getPrograms(): array { return $this->programs; }
    public function getListedDate(): ?string { return $this->listedDate; }
    public function getRemarks(): ?string { return $this->remarks; }
    public function getRaw(): array { return $this->raw; }

    /**
     * Returns all searchable names — primary + all aliases.
     * Used by the matching engine to build the search index.
     *
     * @return string[]
     */
    public function getAllNames(): array
    {
        $names = [$this->primaryName];
        foreach ($this->aliases as $alias) {
            if (!empty($alias['name'])) {
                $names[] = $alias['name'];
            }
        }
        return $names;
    }

    /**
     * Returns the first DOB found, or null.
     */
    public function getPrimaryDOB(): ?string
    {
        foreach ($this->dates as $date) {
            if ($date['type'] === 'date_of_birth') {
                return $date['value'];
            }
        }
        return null;
    }

    /**
     * Content hash for change detection during diffing.
     * If any field changes between syncs, this hash changes.
     */
    public function getContentHash(): string
    {
        return hash('sha256', json_encode([
            $this->primaryName,
            $this->aliases,
            $this->dates,
            $this->nationalities,
            $this->identifiers,
            $this->addresses,
            $this->programs,
            $this->remarks,
        ]));
    }

    public function toArray(): array
    {
        return [
            'source_entity_id' => $this->sourceEntityId,
            'source_id' => $this->sourceId,
            'entity_type' => $this->entityType,
            'primary_name' => $this->primaryName,
            'aliases' => $this->aliases,
            'dates' => $this->dates,
            'nationalities' => $this->nationalities,
            'identifiers' => $this->identifiers,
            'addresses' => $this->addresses,
            'programs' => $this->programs,
            'listed_date' => $this->listedDate,
            'remarks' => $this->remarks,
        ];
    }
}