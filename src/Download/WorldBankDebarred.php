<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\WorldBankDebarredParser;

class WorldBankDebarred extends AbstractCurlSource
{
    private const URL = 'https://apigwext.worldbank.org/dvsvc/v1.0/json/APPLICATION/ADOBE_EXPRNCE_MGR/FIRM/SANCTIONED_FIRM';

    // this is the World Bank's own public frontend key for the debarred
    // firms API: it ships embedded in the JavaScript of worldbank.org and
    // appears verbatim in open-source sanctions aggregators for that reason.
    // It is not a private credential.
    private const API_KEY = 'z9duUaFUiEUYSHs97CU38fcZO7ipOPvm';

    public function getSourceId(): string { return 'wb_debarred'; }
    public function getDisplayName(): string { return 'World Bank Debarred Firms & Individuals'; }
    public function getFormat(): string { return 'json'; }
    protected function url(): string { return self::URL; }

    protected function curlOptions(): array
    {
        // the API gateway checks for the key and browser-like origin headers,
        // and drops connections over HTTP/2
        return [
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => [
                'apikey: ' . self::API_KEY,
                'Origin: https://www.worldbank.org',
                'Referer: https://www.worldbank.org/',
                'Accept: application/json',
                'Content-Type: application/json; charset=utf-8',
            ],
        ];
    }

    public function getParser(): ParserInterface
    {
        return new WorldBankDebarredParser($this->logger);
    }
}
