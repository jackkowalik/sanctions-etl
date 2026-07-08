<?php

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SanctionsEtl\Parse\BelgiumSIFICSVParser;

class BelgiumIdStabilityTest extends TestCase
{
    private const HEADER = "Lastname;Firstname;Middlename;Wholename;Gender;Birth date;Birth place;Birth country;Function;Number;Remark;Embargos;type;Regulation;Publication date;Links";

    private function row(string $last, string $first, string $dob, string $remark = ''): string
    {
        $whole = "{$first} {$last}";
        return "{$last};{$first};;{$whole};M;{$dob};;;;;{$remark};BE;P;;01-06-16;";
    }

    /** @return array<string, string> primary_name => source_entity_id */
    private function parseRows(array $rows): array
    {
        $csv = self::HEADER . "\n" . implode("\n", $rows) . "\n";
        $file = tempnam(sys_get_temp_dir(), 'be_test_');
        file_put_contents($file, $csv);

        $parser = new BelgiumSIFICSVParser(new NullLogger());
        $entities = $parser->parse($file, 'be_sifi');
        unlink($file);

        $ids = [];
        foreach ($entities as $entity) {
            $ids[$entity->toArray()['primary_name']] = $entity->getSourceEntityId();
        }
        return $ids;
    }

    public function testIdsSurviveMidFileInsertion(): void
    {
        $before = $this->parseRows([
            $this->row('ALPHA', 'Anna', '01-02-90'),
            $this->row('BRAVO', 'Bart', '02-03-85'),
            $this->row('CHARLIE', 'Carl', '03-04-80'),
        ]);

        $after = $this->parseRows([
            $this->row('ALPHA', 'Anna', '01-02-90'),
            $this->row('NEWMAN', 'Nina', '05-06-95'),
            $this->row('BRAVO', 'Bart', '02-03-85'),
            $this->row('CHARLIE', 'Carl', '03-04-80'),
        ]);

        $this->assertCount(4, $after);
        foreach ($before as $name => $id) {
            $this->assertSame($id, $after[$name], "entity id for {$name} changed after a mid-file insertion");
        }
    }

    public function testQuotedEmbeddedNewlineSurvivesStreaming(): void
    {
        $ids = $this->parseRows([
            $this->row('ALPHA', 'Anna', '01-02-90'),
            $this->row('DELTA', 'Dave', '04-05-75', "\"line one\nline two\""),
            $this->row('BRAVO', 'Bart', '02-03-85'),
        ]);

        $this->assertCount(3, $ids);
        $this->assertArrayHasKey('Dave DELTA', $ids);
    }
}
