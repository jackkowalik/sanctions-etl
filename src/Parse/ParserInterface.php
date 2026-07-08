<?php

namespace SanctionsEtl\Parse;

use SanctionsEtl\Data\SanctionedEntity;

interface ParserInterface
{
    /**
     * Parse raw content from a sanctions source into normalized entities.
     *
     * @param string $rawContent  The raw file content (XML, CSV, JSON, etc.)
     * @param string $sourceId    Source identifier for tagging parsed entities
     * @return SanctionedEntity[]
     */
    public function parse(string $rawContent, string $sourceId): array;
}