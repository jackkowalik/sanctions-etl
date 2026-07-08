<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\UNConsolidatedXMLParser;

class UNSecurityCouncil extends AbstractCurlSource
{
    private const URL = 'https://unsolprodfiles.blob.core.windows.net/publiclegacyxmlfiles/EN/consolidated.xml';

    public function getSourceId(): string { return 'un_consolidated'; }
    public function getDisplayName(): string { return 'UN Security Council Consolidated List'; }
    public function getFormat(): string { return 'xml'; }
    public function getExpectedUpdateFrequency(): int { return 10080; }
    protected function url(): string { return self::URL; }

    public function getParser(): ParserInterface
    {
        return new UNConsolidatedXMLParser($this->logger);
    }
}
