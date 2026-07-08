<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\SwissSECOXMLParser;

class SwissSECO extends AbstractCurlSource
{
    private const URL = 'https://www.sesam.search.admin.ch/sesam-search-web/pages/downloadXmlGesamtliste.xhtml?lang=de&action=downloadXmlGesamtlisteAction';

    public function getSourceId(): string { return 'ch_seco'; }
    public function getDisplayName(): string { return 'Swiss SECO Sanctions List'; }
    public function getFormat(): string { return 'xml'; }
    protected function url(): string { return self::URL; }

    public function getParser(): ParserInterface
    {
        return new SwissSECOXMLParser($this->logger);
    }
}
