<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\BelgiumSIFICSVParser;

class BelgiumSIFI extends AbstractCurlSource
{
    private const URL = 'https://sifi.minfin.fgov.be/public/api/consolidated-list';

    public function getSourceId(): string { return 'be_sifi'; }
    public function getDisplayName(): string { return 'Belgium SIFI Consolidated Sanctions List'; }
    public function getFormat(): string { return 'csv'; }
    protected function url(): string { return self::URL; }

    protected function curlOptions(): array
    {
        // the endpoint 406s any Accept header naming a concrete type and
        // serves the CSV as application/octet-stream; curl's default
        // Accept: */* is what it wants, so only the HTTP/1.1 force stays
        return [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1];
    }

    public function getParser(): ParserInterface
    {
        return new BelgiumSIFICSVParser($this->logger);
    }
}
