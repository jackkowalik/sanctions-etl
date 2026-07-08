<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\CanadaSEMAXMLParser;

class CanadaSEMA extends AbstractCurlSource
{
    private const URL = 'https://www.international.gc.ca/world-monde/assets/office_docs/international_relations-relations_internationales/sanctions/sema-lmes.xml';

    public function getSourceId(): string { return 'ca_sema'; }
    public function getDisplayName(): string { return 'Canada SEMA Consolidated Sanctions List'; }
    public function getFormat(): string { return 'xml'; }
    public function getExpectedUpdateFrequency(): int { return 10080; }
    protected function url(): string { return self::URL; }

    public function getParser(): ParserInterface
    {
        return new CanadaSEMAXMLParser($this->logger);
    }
}
