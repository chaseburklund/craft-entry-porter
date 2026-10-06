<?php

namespace chaseburklund\entryporter\tests\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\transformers\RelationTransformer;
use chaseburklund\entryporter\tests\Support\FakeResolver;
use PHPUnit\Framework\TestCase;

final class RelationTransformerTest extends TestCase
{
    public function testSupportsRelationClassesOnly(): void
    {
        $t = new RelationTransformer();
        $this->assertTrue($t->supports('craft\\fields\\Assets'));
        $this->assertTrue($t->supports('verbb\\formie\\fields\\Forms'));
        $this->assertFalse($t->supports('craft\\fields\\PlainText'));
    }

    /** Tags fields hold element IDs like the other relation fields and must be remapped. */
    public function testTagsFieldIsRemappedRatherThanCopiedVerbatim(): void
    {
        $t = new RelationTransformer();
        $this->assertTrue($t->supports('craft\\fields\\Tags'));

        $tagRef = Ref::make('tag', 't-uid', ['group' => 'topics', 'title' => 'Bar, Grill']);
        $report = new Report();
        $out = $t->export(
            new FieldDescriptor('craft\\fields\\Tags', 'topics'),
            [7],
            new FakeResolver(elements: ['tag:7' => $tagRef]),
            $report,
        );
        $this->assertSame([$tagRef], $out, 'a tag id must leave as a portable ref, never as the raw source id');
        $this->assertSame([], $report->warnings);

        $back = $t->import(
            new FieldDescriptor('craft\\fields\\Tags', 'topics'),
            $out,
            new FakeResolver(resolutions: ['t-uid' => 4242]),
            $report,
        );
        $this->assertSame([4242], $back, 'the ref must resolve to the TARGET\'s id, not the source\'s');
    }

    public function testExportMapsIdsToRefsAndWarnsOnMissing(): void
    {
        $ref = Ref::make('asset', 'a-uid', ['volume' => 'assets', 'folderPath' => '', 'filename' => 'x.jpg']);
        $resolver = new FakeResolver(elements: ['asset:11' => $ref]);
        $report = new Report();
        $t = new RelationTransformer();
        $out = $t->export(new FieldDescriptor('craft\\fields\\Assets', 'heroImage'), [11, 99], $resolver, $report);
        $this->assertSame([$ref], $out);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('heroImage', $report->warnings[0]);
        $this->assertStringContainsString('99', $report->warnings[0]);
    }

    public function testImportResolvesRefsAndDropsUnresolved(): void
    {
        $good = Ref::make('asset', 'a-uid', []);
        $bad = Ref::make('asset', 'missing-uid', []);
        $resolver = new FakeResolver(resolutions: ['a-uid' => 42]);
        $report = new Report();
        $t = new RelationTransformer();
        $out = $t->import(new FieldDescriptor('craft\\fields\\Assets', 'heroImage'), [$good, $bad], $resolver, $report);
        $this->assertSame([42], $out);
        $this->assertSame([$bad], $report->unresolved);
    }

    public function testNonArrayValuesPassThrough(): void
    {
        $t = new RelationTransformer();
        $report = new Report();
        $this->assertNull($t->export(new FieldDescriptor('craft\\fields\\Assets', 'x'), null, new FakeResolver(), $report));
        $this->assertNull($t->import(new FieldDescriptor('craft\\fields\\Assets', 'x'), null, new FakeResolver(), $report));
    }
}
