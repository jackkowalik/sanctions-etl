<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Config;
use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\AustraliaDFATXLSXParser;

class AustraliaDFAT extends AbstractCurlSource
{
    private const URL = 'https://www.dfat.gov.au/sites/default/files/Australian_Sanctions_Consolidated_List.xlsx';

    public function getSourceId(): string { return 'au_dfat'; }
    public function getDisplayName(): string { return 'Australia DFAT Consolidated Sanctions List'; }
    public function getFormat(): string { return 'xlsx'; }
    protected function url(): string { return self::URL; }

    protected function curlOptions(): array
    {
        // dfat.gov.au bounces non-browser clients and misbehaves over HTTP/2
        return [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ' . Config::USER_AGENT . ')',
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, */*',
            ],
        ];
    }

    public function getParser(): ParserInterface
    {
        return new AustraliaDFATXLSXParser($this->logger);
    }
}
