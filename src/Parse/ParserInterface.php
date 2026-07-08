<?php

namespace SanctionsEtl\Parse;

use SanctionsEtl\Data\SanctionedEntity;

interface ParserInterface
{
    /**
     * Parse a downloaded source file into normalized entities.
     *
     * @param string $filePath  Path to the downloaded file (XML, CSV, JSON)
     * @param string $sourceId  Source identifier for tagging parsed entities
     * @return SanctionedEntity[]
     */
    public function parse(string $filePath, string $sourceId): array;
}
