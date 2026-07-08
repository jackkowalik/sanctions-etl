<?php

namespace SanctionsEtl\Tests;

use PHPUnit\Framework\TestCase;
use SanctionsEtl\Data\SanctionedEntity;

class SanctionedEntityTest extends TestCase
{
    private function entity(array $overrides = []): SanctionedEntity
    {
        $defaults = [
            'sourceEntityId' => '1001',
            'sourceId' => 'test_source',
            'entityType' => 'individual',
            'primaryName' => 'Test Person',
            'aliases' => [
                ['name' => 'Alias A', 'type' => 'aka', 'low_quality' => false],
                ['name' => 'Alias B', 'type' => 'aka', 'low_quality' => true],
            ],
            'dates' => [['type' => 'date_of_birth', 'value' => '1965', 'circa' => true]],
            'nationalities' => ['RU', 'BY'],
            'identifiers' => [],
            'addresses' => [],
            'programs' => ['TEST'],
            'listedDate' => '2020-01-01',
            'remarks' => null,
        ];
        $args = array_merge($defaults, $overrides);

        return new SanctionedEntity(...$args);
    }

    public function testHashIsStable(): void
    {
        $this->assertSame($this->entity()->getContentHash(), $this->entity()->getContentHash());
    }

    public function testAliasReorderingDoesNotChangeHash(): void
    {
        $reordered = $this->entity(['aliases' => [
            ['name' => 'Alias B', 'type' => 'aka', 'low_quality' => true],
            ['name' => 'Alias A', 'type' => 'aka', 'low_quality' => false],
        ]]);

        $this->assertSame($this->entity()->getContentHash(), $reordered->getContentHash());
    }

    public function testNationalityReorderingDoesNotChangeHash(): void
    {
        $reordered = $this->entity(['nationalities' => ['BY', 'RU']]);

        $this->assertSame($this->entity()->getContentHash(), $reordered->getContentHash());
    }

    public function testReclassificationChangesHash(): void
    {
        $reclassified = $this->entity(['entityType' => 'organization']);

        $this->assertNotSame($this->entity()->getContentHash(), $reclassified->getContentHash());
    }

    public function testListedDateChangeChangesHash(): void
    {
        $redated = $this->entity(['listedDate' => '2021-06-15']);

        $this->assertNotSame($this->entity()->getContentHash(), $redated->getContentHash());
    }

    public function testAliasContentChangeChangesHash(): void
    {
        $changed = $this->entity(['aliases' => [
            ['name' => 'Alias A', 'type' => 'aka', 'low_quality' => false],
            ['name' => 'Alias C', 'type' => 'aka', 'low_quality' => true],
        ]]);

        $this->assertNotSame($this->entity()->getContentHash(), $changed->getContentHash());
    }
}
