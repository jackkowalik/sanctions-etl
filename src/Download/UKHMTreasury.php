<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\UKHMTCSVParser;

class UKHMTreasury extends AbstractCurlSource
{
    private const URL = 'https://ofsistorage.blob.core.windows.net/publishlive/2022format/ConList.csv';

    public function getSourceId(): string { return 'gb_hmt'; }
    public function getDisplayName(): string { return 'UK HM Treasury/OFSI Consolidated List'; }
    public function getFormat(): string { return 'csv'; }
    protected function url(): string { return self::URL; }

    public function getParser(): ParserInterface
    {
        return new UKHMTCSVParser($this->logger);
    }
}
