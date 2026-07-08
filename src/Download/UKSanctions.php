<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\UKSanctionsXMLParser;

class UKSanctions extends AbstractCurlSource
{
    private const URL = 'https://sanctionslist.fcdo.gov.uk/docs/UK-Sanctions-List.xml';

    public function getSourceId(): string { return 'uk_sanctions'; }
    public function getDisplayName(): string { return 'UK FCDO Sanctions List'; }
    public function getFormat(): string { return 'xml'; }
    protected function url(): string { return self::URL; }

    public function getParser(): ParserInterface
    {
        return new UKSanctionsXMLParser($this->logger);
    }
}
