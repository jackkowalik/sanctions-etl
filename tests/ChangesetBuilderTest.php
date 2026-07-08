<?php

namespace SanctionsEtl\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SanctionsEtl\Data\SanctionedEntity;
use SanctionsEtl\Diff\ChangesetBuilder;

class ChangesetBuilderTest extends TestCase
{
    private function entity(string $id, string $name): SanctionedEntity
    {
        return new SanctionedEntity(
            sourceEntityId: $id,
            sourceId: 'test_source',
            entityType: 'individual',
            primaryName: $name,
        );
    }

    public function testNewEntityIsInsert(): void
    {
        $builder = new ChangesetBuilder(new NullLogger());
        $changeset = $builder->build('test_source', [$this->entity('1', 'A')], []);

        $this->assertCount(1, $changeset->getInserts());
        $this->assertCount(0, $changeset->getUpdates());
        $this->assertCount(0, $changeset->getDelists());
    }

    public function testUnchangedEntityIsNoOp(): void
    {
        $entity = $this->entity('1', 'A');
        $builder = new ChangesetBuilder(new NullLogger());
        $changeset = $builder->build('test_source', [$entity], ['1' => $entity->getContentHash()]);

        $this->assertTrue($changeset->isEmpty());
    }

    public function testChangedHashIsUpdate(): void
    {
        $builder = new ChangesetBuilder(new NullLogger());
        $changeset = $builder->build('test_source', [$this->entity('1', 'A')], ['1' => 'stale_hash']);

        $this->assertCount(0, $changeset->getInserts());
        $this->assertCount(1, $changeset->getUpdates());
        $this->assertCount(0, $changeset->getDelists());
    }

    public function testMissingEntityIsDelist(): void
    {
        $entity = $this->entity('1', 'A');
        $builder = new ChangesetBuilder(new NullLogger());
        $changeset = $builder->build(
            'test_source',
            [$entity],
            ['1' => $entity->getContentHash(), '2' => 'some_hash']
        );

        $this->assertCount(0, $changeset->getInserts());
        $this->assertCount(0, $changeset->getUpdates());
        $this->assertSame(['2'], $changeset->getDelists());
    }
}
