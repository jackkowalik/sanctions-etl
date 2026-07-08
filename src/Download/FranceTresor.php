<?php

namespace SanctionsEtl\Download;

use SanctionsEtl\Parse\ParserInterface;
use SanctionsEtl\Parse\FranceTresorParser;

class FranceTresor extends AbstractCurlSource
{
    private const URL = 'https://gels-avoirs.dgtresor.gouv.fr/ApiPublic/api/v1/publication/derniere-publication-fichier-json';

    public function getSourceId(): string { return 'fr_tresor'; }
    public function getDisplayName(): string { return 'France DG Tresor National Freezing Registry'; }
    public function getFormat(): string { return 'json'; }
    protected function url(): string { return self::URL; }

    protected function curlOptions(): array
    {
        // the DG Tresor API drops transfers over HTTP/2
        return [CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1];
    }

    public function getParser(): ParserInterface
    {
        return new FranceTresorParser($this->logger);
    }
}
