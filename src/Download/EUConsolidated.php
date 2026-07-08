<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\EUConsolidatedXMLParser;

class EUConsolidated extends AbstractCurlSource
{
    private const URL = 'https://webgate.ec.europa.eu/fsd/fsf/public/files/xmlFullSanctionsList/content?token=dG9rZW4tMjAxNw';

    public function getSourceId(): string { return 'eu_consolidated'; }
    public function getDisplayName(): string { return 'EU Consolidated Financial Sanctions List'; }
    public function getFormat(): string { return 'xml'; }
    protected function url(): string { return self::URL; }

    public function getParser(): ParserInterface
    {
        return new EUConsolidatedXMLParser($this->logger);
    }
}
