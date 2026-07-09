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

    /**
     * Number of records that failed to parse during the last parse() run.
     * The sync refuses to apply delists alongside nonzero parse errors,
     * since a record that fails to parse is indistinguishable from one
     * that left the list.
     */
    public function getErrorCount(): int;
}
