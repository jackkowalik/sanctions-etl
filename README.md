# sanctions-etl

> All 15 sources are live. MySQL storage mode is still in progress.

Sanctions data ETL in PHP. Syncs official sanctions and exclusion lists from
15 government and institutional sources into a normalized dataset. JSONL by
default, MySQL optionally.

## Quickstart

Requires PHP 8.1+ with curl, xmlreader, and zip extensions (composer will
tell you if anything is missing).

    git clone https://github.com/jackkowalik/sanctions-etl.git
    cd sanctions-etl
    composer install
    cp .env.example .env
    php bin/sync.php

This produces one JSONL file per source in `out/`, plus `manifest.json`
(per-source hashes, counts, last changeset) and `sync_log.jsonl` (an
append-only audit log of every sync run).

Re-running: each source's content hash is checked before parsing,
so unchanged lists are skipped.

## Sample output

One line per entity. Here is an example record from the UN Security Council
consolidated list, pretty printed:

    {
      "source_entity_id": "110404",
      "source_id": "un_consolidated",
      "entity_type": "individual",
      "primary_name": "MOHAMMAD BAQER ZOLQADR",
      "aliases": [
        {"name": "Mohammad Bakr Zolqadr", "type": "aka", "low_quality": false},
        {"name": "Mohammad Bakr Zolkadr", "type": "aka", "low_quality": false},
        {"name": "Mohammad Baqer Zolqadir", "type": "aka", "low_quality": false},
        {"name": "Mohammad Baqer Zolqader", "type": "aka", "low_quality": false}
      ],
      "dates": [],
      "nationalities": [],
      "identifiers": [],
      "addresses": [],
      "programs": ["Iran"],
      "listed_date": "2007-03-24",
      "remarks": "[Old Reference # I.47.D.7]",
      "content_hash": "46db16bf..."
    }

Every entity carries the same shape regardless of source: names, aliases
with quality flags, dates, nationalities, identity documents, addresses,
sanction programs, listing date, and remarks, plus a content hash used for
change detection between syncs.

## Sources

| Source | ID | Format |
|---|---|---|
| UN Security Council Consolidated List | un_consolidated | XML |
| EU Consolidated Financial Sanctions List | eu_consolidated | XML |
| Canada SEMA Consolidated Sanctions List | ca_sema | XML |
| OFAC SDN List | ofac_sdn | XML |
| OFAC Consolidated Non-SDN List | ofac_consolidated | XML |
| UK Sanctions List | uk_sanctions | XML |
| UK HM Treasury / OFSI Consolidated List | gb_hmt | CSV |
| US Consolidated Screening List (11 sub-lists) | see below | CSV |
| FBI Most Wanted | us_fbi_wanted | JSON |
| Swiss SECO Sanctions List | ch_seco | XML |
| Australia DFAT Consolidated List | au_dfat | XLSX |
| World Bank Debarred Firms | wb_debarred | JSON |
| Belgium SIFI National List | be_sifi | CSV |
| France DG Tresor National Freezing List | fr_tresor | JSON |
| US SAM.gov Procurement Exclusions | us_sam_exclusions | CSV |

The CSL is a single download that fans out into 11 sub-list source IDs:
us_bis_entity, us_bis_denied, us_bis_unverified, us_bis_meu, us_cmic,
us_itar_debarred, us_isn_nonprolif, us_ofac_ssi, us_ofac_mbs, us_ofac_plc,
and us_ofac_capta. SDN entries are excluded from the CSL parse since they
are already ingested from the richer OFAC advanced XML.

SAM.gov is the one source requiring a key. A free one from your sam.gov
account, set as SAM_API_KEY in `.env`. Left unset, that source is skipped
with a log line and everything else syncs just fine.

## How it works

Each sync is a four-stage pipeline per source: fetch (with hash
short-circuit), parse into the normalized entity model, diff against the
current stored state to compute the inserts / updates / delists, and apply the
changeset to the storage backend.

## MySQL mode

Not implemented yet. The storage layer is an interface, so a MySQL backend
slots in without touching the pipeline. Planned: relational tables for
entities, aliases, identifiers, and addresses, delist timestamps instead of
file removal, plus schema.sql and a docker-compose setup.

## Roadmap

A screening engine. Basically name matching against this dataset with transliteration
and fuzzy-match support.

## License

MIT. The lists themselves are published by their respective governments and
institutions as public records.
