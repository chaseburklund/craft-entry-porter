<?php

namespace chaseburklund\entryporter\tests\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;
use chaseburklund\entryporter\transformers\NeoTransformer;
use chaseburklund\entryporter\transformers\RelationTransformer;
use chaseburklund\entryporter\tests\Support\FakeResolver;
use PHPUnit\Framework\TestCase;

final class NeoTransformerTest extends TestCase
{
    private function registry(): Registry
    {
        $fallback = new class implements TransformerInterface {
            public function supports(string $c): bool { return true; }
            public function export(FieldDescriptor $f, mixed $v, ResolverInterface $r, Report $rep): mixed { return $v; }
            public function import(FieldDescriptor $f, mixed $v, ResolverInterface $r, Report $rep): mixed { return $v; }
        };
        $registry = new Registry($fallback);
        $registry->add(new RelationTransformer());
        $registry->add(new NeoTransformer($registry));
        return $registry;
    }

    public function testSupportsOnlyNeoField(): void
    {
        $t = new NeoTransformer($this->registry());
        $this->assertTrue($t->supports('benf\\neo\\Field'));
        $this->assertFalse($t->supports('craft\\fields\\Matrix'));
        $this->assertFalse($t->supports('verbb\\supertable\\fields\\SuperTableField'));
    }

    public function testExportPreservesHierarchyAndTransformsNestedFields(): void
    {
        // Numeric source keys and two blocks, so the re-keying is actually exercised.
        $assetRef = Ref::make('asset', 'img-uid', []);
        $resolver = new FakeResolver(
            elements: ['asset:12' => $assetRef],
            neoBlockFields: [
                'pageBuilder:layoutMulti' => [],
                'pageBuilder:column' => ['image' => new FieldDescriptor('craft\\fields\\Assets', 'image')],
            ],
        );
        $report = new Report();
        $value = [
            '301' => ['type' => 'layoutMulti', 'enabled' => true, 'collapsed' => false, 'level' => 1, 'fields' => []],
            '302' => ['type' => 'column', 'enabled' => true, 'collapsed' => false, 'level' => 2,
                      'fields' => ['image' => [12]]],
        ];
        $t = new NeoTransformer($this->registry());
        $out = $t->export(new FieldDescriptor('benf\\neo\\Field', 'pageBuilder'), $value, $resolver, $report);
        $this->assertSame(['new1', 'new2'], array_keys($out));
        $this->assertSame(1, $out['new1']['level']);
        $this->assertSame(2, $out['new2']['level']);
        $this->assertSame([$assetRef], $out['new2']['fields']['image']);
        $this->assertSame([], $report->warnings);
    }

    public function testImportResolvesNestedRefsAndReKeysFromSourceStyleInput(): void
    {
        // Likewise on import.
        $assetRef = Ref::make('asset', 'img-uid', []);
        $resolver = new FakeResolver(
            resolutions: ['img-uid' => 88],
            neoBlockFields: [
                'pageBuilder:layoutMulti' => [],
                'pageBuilder:column' => ['image' => new FieldDescriptor('craft\\fields\\Assets', 'image')],
            ],
        );
        $report = new Report();
        $value = [
            '401' => ['type' => 'layoutMulti', 'enabled' => true, 'collapsed' => false, 'level' => 1, 'fields' => []],
            '402' => ['type' => 'column', 'enabled' => true, 'collapsed' => false, 'level' => 2,
                      'fields' => ['image' => [$assetRef]]],
        ];
        $t = new NeoTransformer($this->registry());
        $out = $t->import(new FieldDescriptor('benf\\neo\\Field', 'pageBuilder'), $value, $resolver, $report);
        $this->assertSame(['new1', 'new2'], array_keys($out));
        $this->assertSame(1, $out['new1']['level']);
        $this->assertSame(2, $out['new2']['level']);
        $this->assertSame([88], $out['new2']['fields']['image']);
    }

    public function testPreservesBlockOrderAndLevelsAcrossMultipleBlocks(): void
    {
        // Order and each block's level must both be preserved.
        $resolver = new FakeResolver(
            neoBlockFields: [
                'pageBuilder:layoutMulti' => [],
                'pageBuilder:column' => [],
                'pageBuilder:text' => [],
            ],
        );
        $report = new Report();
        $value = [
            '10' => ['type' => 'layoutMulti', 'enabled' => true, 'level' => 1, 'fields' => []],
            '11' => ['type' => 'column', 'enabled' => true, 'level' => 2, 'fields' => []],
            '12' => ['type' => 'text', 'enabled' => true, 'level' => 3, 'fields' => []],
        ];
        $t = new NeoTransformer($this->registry());
        $out = $t->export(new FieldDescriptor('benf\\neo\\Field', 'pageBuilder'), $value, $resolver, $report);
        $this->assertSame(['new1', 'new2', 'new3'], array_keys($out));
        $this->assertSame([1, 2, 3], array_map(fn($b) => $b['level'], array_values($out)));
        $this->assertSame(['layoutMulti', 'column', 'text'], array_map(fn($b) => $b['type'], array_values($out)));
    }

    public function testUnknownNestedFieldHandleIsCopiedVerbatimWithWarning(): void
    {
        $resolver = new FakeResolver(neoBlockFields: ['pageBuilder:column' => []]);
        $report = new Report();
        $value = ['301' => ['type' => 'column', 'enabled' => true, 'level' => 1, 'fields' => ['mystery' => 'val']]];
        $t = new NeoTransformer($this->registry());
        $out = $t->export(new FieldDescriptor('benf\\neo\\Field', 'pageBuilder'), $value, $resolver, $report);
        $this->assertSame('val', $out['new1']['fields']['mystery']);
        $this->assertNotEmpty($report->warnings);
    }

    public function testWarnsAndSkipsNonArrayBlock(): void
    {
        $resolver = new FakeResolver(neoBlockFields: ['pageBuilder:column' => []]);
        $report = new Report();
        $value = [
            '301' => 'not-an-array',
            '302' => ['type' => 'column', 'enabled' => true, 'level' => 1, 'fields' => []],
        ];
        $t = new NeoTransformer($this->registry());
        $out = $t->export(new FieldDescriptor('benf\\neo\\Field', 'pageBuilder'), $value, $resolver, $report);
        // The skipped block does not use up a key: the remaining block is still "new1".
        $this->assertSame(['new1'], array_keys($out));
        $this->assertNotEmpty($report->warnings);
        $this->assertStringContainsString('not an array', $report->warnings[0]);
    }

    public function testWarnsAndSkipsBlockWithNoTypeHandle(): void
    {
        $resolver = new FakeResolver(neoBlockFields: ['pageBuilder:column' => []]);
        $report = new Report();
        $value = [
            '301' => ['enabled' => true, 'level' => 1, 'fields' => []],
            '302' => ['type' => 'column', 'enabled' => true, 'level' => 2, 'fields' => []],
        ];
        $t = new NeoTransformer($this->registry());
        $out = $t->export(new FieldDescriptor('benf\\neo\\Field', 'pageBuilder'), $value, $resolver, $report);
        $this->assertSame(['new1'], array_keys($out));
        $this->assertSame('column', $out['new1']['type']);
        $this->assertNotEmpty($report->warnings);
        $this->assertStringContainsString('no type handle', $report->warnings[0]);
    }

    public function testNeverEmitsUidEvenIfPresentOnSourceBlock(): void
    {
        // Source block UIDs are never sent, so they cannot collide with blocks on the target.
        $resolver = new FakeResolver(neoBlockFields: ['pageBuilder:column' => []]);
        $report = new Report();
        $value = ['301' => ['uid' => 'source-block-uid', 'type' => 'column', 'enabled' => true, 'level' => 1, 'fields' => []]];
        $t = new NeoTransformer($this->registry());
        $out = $t->export(new FieldDescriptor('benf\\neo\\Field', 'pageBuilder'), $value, $resolver, $report);
        $this->assertArrayNotHasKey('uid', $out['new1']);
    }

    public function testPreservesCollapsedWhenPresent(): void
    {
        $resolver = new FakeResolver(neoBlockFields: ['pageBuilder:column' => []]);
        $report = new Report();
        $value = ['301' => ['type' => 'column', 'enabled' => true, 'collapsed' => true, 'level' => 1, 'fields' => []]];
        $t = new NeoTransformer($this->registry());
        $out = $t->export(new FieldDescriptor('benf\\neo\\Field', 'pageBuilder'), $value, $resolver, $report);
        $this->assertTrue($out['new1']['collapsed']);
    }

    public function testOmitsCollapsedWhenAbsentFromSource(): void
    {
        $resolver = new FakeResolver(neoBlockFields: ['pageBuilder:column' => []]);
        $report = new Report();
        $value = ['301' => ['type' => 'column', 'enabled' => true, 'level' => 1, 'fields' => []]];
        $t = new NeoTransformer($this->registry());
        $out = $t->export(new FieldDescriptor('benf\\neo\\Field', 'pageBuilder'), $value, $resolver, $report);
        $this->assertArrayNotHasKey('collapsed', $out['new1']);
    }

    public function testNonArrayValuePassesThroughUnchanged(): void
    {
        $resolver = new FakeResolver();
        $report = new Report();
        $t = new NeoTransformer($this->registry());
        $out = $t->export(new FieldDescriptor('benf\\neo\\Field', 'pageBuilder'), null, $resolver, $report);
        $this->assertNull($out);
    }

    /** A non-string block type would throw if passed to the resolver, so the block is skipped. */
    public function testNonStringBlockTypeIsSkippedWithWarning(): void
    {
        foreach ([['column'], 5, true, ['a' => 'b']] as $badType) {
            $resolver = new FakeResolver(neoBlockFields: ['pageBuilder:column' => []]);
            $report = new Report();
            $value = [
                '301' => ['type' => $badType, 'enabled' => true, 'level' => 1, 'fields' => ['mystery' => 'v']],
                '302' => ['type' => 'column', 'enabled' => true, 'level' => 2, 'fields' => []],
            ];
            $t = new NeoTransformer($this->registry());

            $out = $t->import(new FieldDescriptor('benf\\neo\\Field', 'pageBuilder'), $value, $resolver, $report);

        // The skipped block does not use up a key.
            $this->assertSame(['new1'], array_keys($out));
            $this->assertSame('column', $out['new1']['type']);
            $this->assertNotEmpty($report->warnings);
            $this->assertStringContainsString('type handle', $report->warnings[0]);
        }
    }

    public function testNonArrayBlockFieldsIsReportedRatherThanIterated(): void
    {
        $resolver = new FakeResolver(neoBlockFields: ['pageBuilder:column' => []]);
        $report = new Report();
        $value = ['301' => ['type' => 'column', 'enabled' => true, 'level' => 1, 'fields' => 'oops']];
        $t = new NeoTransformer($this->registry());

        $out = $t->import(new FieldDescriptor('benf\\neo\\Field', 'pageBuilder'), $value, $resolver, $report);

        $this->assertSame([], $out['new1']['fields']);
        $this->assertNotEmpty($report->warnings);
        $this->assertStringContainsString('fields', $report->warnings[0]);
    }
}
