<?php

namespace SanctionsEtl\Parse;

use Psr\Log\LoggerInterface;
use SanctionsEtl\Data\SanctionedEntity;

/**
 * Parser for France DG Tresor National Freezing Registry (Registre national des gels).
 *
 * JSON structure:
 * {
 *   "Publications": {
 *     "PublicationDate": "...",
 *     "RegistreDetail": [
 *       {
 *         "IdRegistre": 3912,
 *         "Nature": "Personne physique" | "Personne morale",
 *         "Nom": "SKACHKOV",
 *         "RegistreDetail": [
 *           {"TypeChamp": "PRENOM", "Valeur": [{"Prenom": "Aleksandr"}]},
 *           {"TypeChamp": "ALIAS", "Valeur": [{"Alias": "...", "Commentaire": "..."}]},
 *           {"TypeChamp": "DATE_DE_NAISSANCE", "Valeur": [{"Jour":"21","Mois":"11","Annee":"1960"}]},
 *           {"TypeChamp": "NATIONALITE", "Valeur": [{"Pays": "RUSSIE"}]},
 *           {"TypeChamp": "ADRESSE_PM", "Valeur": [{"Adresse":"...","Pays":"..."}]},
 *           {"TypeChamp": "IDENTIFICATION", "Valeur": [{"Identification":"...","Commentaire":"..."}]},
 *           {"TypeChamp": "MOTIFS", "Valeur": [{"Motifs": "..."}]},
 *           {"TypeChamp": "FONDEMENT_JURIDIQUE", "Valeur": [...]},
 *           {"TypeChamp": "REFERENCE_UE", "Valeur": [{"ReferenceUe": "EU.9387.41"}]},
 *           {"TypeChamp": "PASSEPORT", "Valeur": [{"NumeroPasseport":"..."}]},
 *           {"TypeChamp": "AUTRE_IDENTITE", "Valeur": [{"NumeroCarte":"...","Commentaire":"..."}]},
 *           ...
 *         ]
 *       }
 *     ]
 *   }
 * }
 */
class FranceTresorParser implements ParserInterface
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function parse(string $filePath, string $sourceId): array
    {
        $this->logger->info("Starting France Tresor parse", [
            'file' => $filePath,
            'source_id' => $sourceId,
        ]);

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read file: {$filePath}");
        }

        $data = json_decode($raw, true);
        if ($data === null) {
            throw new \RuntimeException("Invalid JSON in: {$filePath}");
        }

        $records = $this->extractRecords($data);
        if (empty($records)) {
            throw new \RuntimeException("No records found in France Tresor response");
        }

        $this->logger->info("France Tresor records extracted", ['count' => count($records)]);

        $entities = [];
        $errors = 0;

        foreach ($records as $record) {
            try {
                $entity = $this->parseRecord($record, $sourceId);
                if ($entity !== null) {
                    $entities[] = $entity;
                }
            } catch (\Exception $e) {
                $errors++;
                if ($errors <= 10) {
                    $this->logger->error("Failed to parse France Tresor record", [
                        'id' => $record['IdRegistre'] ?? '?',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->logger->info("France Tresor parse complete", [
            'source_id' => $sourceId,
            'entities' => count($entities),
            'errors' => $errors,
        ]);

        return $entities;
    }

    private function extractRecords(array $data): array
    {
        // Navigate: Publications -> PublicationDetail (array of entities)
        $pub = $data['Publications'] ?? $data;

        if (isset($pub['PublicationDetail']) && is_array($pub['PublicationDetail'])) {
            return $pub['PublicationDetail'];
        }

        if (isset($pub['RegistreDetail']) && is_array($pub['RegistreDetail'])) {
            return $pub['RegistreDetail'];
        }

        // Try one level deeper
        foreach ($pub as $key => $val) {
            if (is_array($val) && isset($val['RegistreDetail'])) {
                return $val['RegistreDetail'];
            }
            if (is_array($val) && isset($val[0]['IdRegistre'])) {
                return $val;
            }
        }

        return [];
    }

    private function parseRecord(array $record, string $sourceId): ?SanctionedEntity
    {
        $idRegistre = (string)($record['IdRegistre'] ?? '');
        if ($idRegistre === '') return null;

        $nom = trim($record['Nom'] ?? '');
        if ($nom === '') return null;

        $nature = mb_strtolower($record['Nature'] ?? '');
        $entityType = match (true) {
            str_contains($nature, 'physique') => 'individual',
            str_contains($nature, 'morale') => 'organization',
            default => 'unknown',
        };

        $fields = $this->indexFields($record['RegistreDetail'] ?? []);

        // Build primary name
        $prenom = $this->getFirstValue($fields, 'PRENOM', 'Prenom');
        $primaryName = $prenom !== '' ? "{$prenom} {$nom}" : $nom;

        // Aliases
        $aliases = [];
        foreach ($this->getAllValues($fields, 'ALIAS') as $aliasEntry) {
            $aliasName = trim($aliasEntry['Alias'] ?? '');
            if ($aliasName !== '' && $aliasName !== $primaryName) {
                $aliases[] = [
                    'name' => $aliasName,
                    'type' => 'aka',
                    'low_quality' => false,
                ];
            }
        }

        // DOB
        $dates = [];
        foreach ($this->getAllValues($fields, 'DATE_DE_NAISSANCE') as $dobEntry) {
            $year = trim($dobEntry['Annee'] ?? '');
            $month = trim($dobEntry['Mois'] ?? '');
            $day = trim($dobEntry['Jour'] ?? '');

            if ($year === '') continue;

            if ($month !== '' && $day !== '') {
                $val = sprintf('%s-%02d-%02d', $year, (int)$month, (int)$day);
            } elseif ($month !== '') {
                $val = sprintf('%s-%02d', $year, (int)$month);
            } else {
                $val = $year;
            }

            $dates[] = ['type' => 'date_of_birth', 'value' => $val, 'circa' => false];
        }

        // Nationalities
        $nationalities = [];
        foreach ($this->getAllValues($fields, 'NATIONALITE') as $natEntry) {
            $pays = trim($natEntry['Pays'] ?? '');
            if ($pays !== '') {
                $nationalities[] = $pays;
            }
        }

        // Addresses (for entities: ADRESSE_PM)
        $addresses = [];
        foreach ($this->getAllValues($fields, 'ADRESSE_PM') as $addrEntry) {
            $addr = trim($addrEntry['Adresse'] ?? '');
            $country = trim($addrEntry['Pays'] ?? '');
            if ($addr !== '' || $country !== '') {
                $addresses[] = [
                    'full' => $addr,
                    'city' => '',
                    'region' => '',
                    'postal' => '',
                    'country' => $country,
                ];
            }
        }

        // Identifiers: passports, tax IDs, other
        $identifiers = [];
        foreach ($this->getAllValues($fields, 'PASSEPORT') as $passEntry) {
            $num = trim($passEntry['NumeroPasseport'] ?? '');
            if ($num !== '') {
                $identifiers[] = [
                    'type' => 'Passport',
                    'value' => $num,
                    'country' => '',
                    'valid' => true,
                ];
            }
        }
        foreach ($this->getAllValues($fields, 'AUTRE_IDENTITE') as $idEntry) {
            $num = trim($idEntry['NumeroCarte'] ?? '');
            $comment = trim($idEntry['Commentaire'] ?? '');
            if ($num !== '') {
                $identifiers[] = [
                    'type' => $comment ?: 'National ID',
                    'value' => $num,
                    'country' => '',
                    'valid' => true,
                ];
            }
        }
        foreach ($this->getAllValues($fields, 'IDENTIFICATION') as $idEntry) {
            $num = trim($idEntry['Identification'] ?? '');
            $comment = trim($idEntry['Commentaire'] ?? '');
            if ($num !== '' && $num !== '/') {
                $identifiers[] = [
                    'type' => $comment ?: 'Registration',
                    'value' => $num,
                    'country' => '',
                    'valid' => true,
                ];
            }
        }

        // Programs from legal basis
        $programs = [];
        foreach ($this->getAllValues($fields, 'FONDEMENT_JURIDIQUE') as $fjEntry) {
            $label = trim($fjEntry['FondementJuridiqueLabel'] ?? '');
            if ($label !== '') {
                // Extract program name from label, e.g. "(UE Ukraine integriti territoriale...)"
                if (preg_match('/\((?:UE|ONU)\s+(.+?)\s*-/', $label, $m)) {
                    $prog = trim($m[1]);
                    if (!in_array($prog, $programs, true)) {
                        $programs[] = $prog;
                    }
                }
            }
        }

        // EU reference
        $euRefs = [];
        foreach ($this->getAllValues($fields, 'REFERENCE_UE') as $refEntry) {
            $ref = trim($refEntry['ReferenceUe'] ?? '');
            if ($ref !== '') {
                $euRefs[] = $ref;
            }
        }

        // Motifs as remarks
        $motifs = $this->getFirstValue($fields, 'MOTIFS', 'Motifs');
        $remarks = $motifs !== '' ? $motifs : null;
        if ($remarks !== null && strlen($remarks) > 1000) {
            $remarks = substr($remarks, 0, 1000);
        }

        // Title
        $titre = $this->getFirstValue($fields, 'TITRE', 'Titre');
        if ($titre !== '' && $remarks !== null) {
            $remarks = "Title: {$titre}; {$remarks}";
            if (strlen($remarks) > 1000) {
                $remarks = substr($remarks, 0, 1000);
            }
        } elseif ($titre !== '') {
            $remarks = "Title: {$titre}";
        }

        // Gender
        $sexe = $this->getFirstValue($fields, 'SEXE', 'Sexe');

        return new SanctionedEntity(
            sourceEntityId: $idRegistre,
            sourceId: $sourceId,
            entityType: $entityType,
            primaryName: $primaryName,
            aliases: $aliases,
            dates: $dates,
            nationalities: $nationalities,
            identifiers: $identifiers,
            addresses: $addresses,
            programs: $programs,
            listedDate: null,
            remarks: $remarks,
            raw: [
                'eu_references' => implode(', ', $euRefs),
                'gender' => $sexe,
            ]
        );
    }

    /**
     * Index RegistreDetail fields by TypeChamp for fast lookup.
     * Returns: ['PRENOM' => [values...], 'ALIAS' => [values...], ...]
     */
    private function indexFields(array $details): array
    {
        $indexed = [];
        foreach ($details as $field) {
            $type = $field['TypeChamp'] ?? '';
            if ($type === '') continue;
            $indexed[$type] = $field['Valeur'] ?? [];
        }
        return $indexed;
    }

    private function getFirstValue(array $fields, string $type, string $key): string
    {
        if (!isset($fields[$type]) || empty($fields[$type])) return '';
        $first = $fields[$type][0] ?? [];
        return trim((string)($first[$key] ?? ''));
    }

    private function getAllValues(array $fields, string $type): array
    {
        return $fields[$type] ?? [];
    }
}