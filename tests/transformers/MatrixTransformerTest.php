<?php

namespace chaseburklund\entryporter\tests\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;
use chaseburklund\entryporter\transformers\MatrixTransformer;
use chaseburklund\entryporter\transformers\RelationTransformer;
use chaseburklund\entryporter\tests\Support\FakeResolver;
use PHPUnit\Framework\TestCase;

final class MatrixTransformerTest extends TestCase
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
        $registry->add(new MatrixTransformer($registry));
        return $registry;
    }

    public function testExportReKeysBlocksAndTransformsNestedRelations(): void
    {
        $assetRef1 = Ref::make('asset', 'a-uid-1', ['volume' => 'assets']);
        $assetRef2 = Ref::make('asset', 'a-uid-2', ['volume' => 'assets']);
        $resolver = new FakeResolver(
            elements: ['asset:7' => $assetRef1, 'asset:8' => $assetRef2],
            entryTypeFields: ['quoteRow' => [
                'photo' => new FieldDescriptor('craft\\fields\\Assets', 'photo'),
                'quote' => new FieldDescriptor('craft\\fields\\PlainText', 'quote'),
            ]],
        );
        $report = new Report();
        $value = [
            '4021' => ['title' => null, 'slug' => 'row-1', 'type' => 'quoteRow', 'enabled' => true, 'collapsed' => false,
                       'fields' => ['photo' => [7], 'quote' => 'Hello']],
            '4022' => ['title' => null, 'slug' => 'row-2', 'type' => 'quoteRow', 'enabled' => true, 'collapsed' => false,
                       'fields' => ['photo' => [8], 'quote' => 'World']],
        ];
        $t = new MatrixTransformer($this->registry());
        $out = $t->export(new FieldDescriptor('verbb\\supertable\\fields\\SuperTableField', 'quotes'), $value, $resolver, $report);
        $this->assertSame(['new1', 'new2'], array_keys($out));
        $this->assertSame('quoteRow', $out['new1']['type']);
        $this->assertSame([$assetRef1], $out['new1']['fields']['photo']);
        $this->assertSame('Hello', $out['new1']['fields']['quote']);
        $this->assertSame('quoteRow', $out['new2']['type']);
        $this->assertSame([$assetRef2], $out['new2']['fields']['photo']);
        $this->assertSame('World', $out['new2']['fields']['quote']);
    }

    public function testImportResolvesNestedRefsAndReKeys(): void
    {
        // Numeric source keys and two blocks, so the re-keying is actually exercised.
        $assetRef1 = Ref::make('asset', 'a-uid-1', []);
        $assetRef2 = Ref::make('asset', 'a-uid-2', []);
        $resolver = new FakeResolver(
            resolutions: ['a-uid-1' => 55, 'a-uid-2' => 66],
            entryTypeFields: ['quoteRow' => ['photo' => new FieldDescriptor('craft\\fields\\Assets', 'photo')]],
        );
        $report = new Report();
        $value = [
            '4021' => ['type' => 'quoteRow', 'enabled' => true, 'fields' => ['photo' => [$assetRef1]]],
            '4022' => ['type' => 'quoteRow', 'enabled' => true, 'fields' => ['photo' => [$assetRef2]]],
        ];
        $t = new MatrixTransformer($this->registry());
        $out = $t->import(new FieldDescriptor('craft\\fields\\Matrix', 'quotes'), $value, $resolver, $report);
        $this->assertSame(['new1', 'new2'], array_keys($out));
        $this->assertSame([55], $out['new1']['fields']['photo']);
        $this->assertSame([66], $out['new2']['fields']['photo']);
    }

    public function testUnknownNestedFieldHandleIsCopiedVerbatimWithWarning(): void
    {
        $resolver = new FakeResolver(entryTypeFields: ['quoteRow' => []]);
        $report = new Report();
        $value = ['9' => ['type' => 'quoteRow', 'enabled' => true, 'fields' => ['mystery' => 'val']]];
        $t = new MatrixTransformer($this->registry());
        $out = $t->export(new FieldDescriptor('craft\\fields\\Matrix', 'quotes'), $value, $resolver, $report);
        $this->assertSame('val', $out['new1']['fields']['mystery']);
        $this->assertNotEmpty($report->warnings);
    }

    public function testNonArrayBlockIsSkippedWithWarning(): void
    {
        $resolver = new FakeResolver();
        $report = new Report();
        $value = ['4021' => 'not-a-block'];
        $t = new MatrixTransformer($this->registry());
        $out = $t->export(new FieldDescriptor('craft\\fields\\Matrix', 'quotes'), $value, $resolver, $report);
        $this->assertSame([], $out);
        $this->assertNotEmpty($report->warnings);
        $this->assertStringContainsString('malformed', $report->warnings[0]);
    }

    public function testBlockWithNoTypeHandleWarnsThatTargetWillDropIt(): void
    {
        $resolver = new FakeResolver();
        $report = new Report();
        $value = ['4021' => ['enabled' => true, 'fields' => []]];
        $t = new MatrixTransformer($this->registry());
        $out = $t->export(new FieldDescriptor('craft\\fields\\Matrix', 'quotes'), $value, $resolver, $report);
        $this->assertArrayNotHasKey('type', $out['new1']);
        $this->assertNotEmpty($report->warnings);
        $this->assertStringContainsString('no type handle', $report->warnings[0]);
    }

    /**
     * A non-string block type would throw if passed to the resolver, so it is reported and
     * treated as missing.
     */
    public function testNonStringBlockTypeIsReportedAndNotHandedToTheResolver(): void
    {
        foreach ([['quoteRow'], 5, true, ['a' => 'b']] as $badType) {
            $resolver = new FakeResolver(entryTypeFields: ['quoteRow' => []]);
            $report = new Report();
            $value = ['4021' => ['type' => $badType, 'enabled' => true, 'fields' => ['quote' => 'Hi']]];
            $t = new MatrixTransformer($this->registry());

            $out = $t->import(new FieldDescriptor('craft\\fields\\Matrix', 'quotes'), $value, $resolver, $report);

            $this->assertArrayNotHasKey('type', $out['new1'], 'an unusable type must not be forwarded');
            $this->assertNotEmpty($report->warnings);
            $this->assertStringContainsString('type handle', $report->warnings[0]);
        }
    }

    /** One warning, not two, for one problem. */
    public function testNonStringBlockTypeProducesOneWarningNotTwo(): void
    {
        $resolver = new FakeResolver(entryTypeFields: ['quoteRow' => []]);
        $report = new Report();
        $value = ['4021' => ['type' => ['quoteRow'], 'enabled' => true, 'fields' => []]];
        $t = new MatrixTransformer($this->registry());
        $t->import(new FieldDescriptor('craft\\fields\\Matrix', 'quotes'), $value, $resolver, $report);
        $this->assertCount(1, $report->warnings);
    }

    /**
     * Craft assigns a block's title and slug to nullable string properties, so other types
     * are dropped with a warning. `collapsed` needs no check.
     */
    public function testNonStringBlockTitleOrSlugIsDroppedWithWarning(): void
    {
        foreach (['title', 'slug'] as $key) {
            $resolver = new FakeResolver(entryTypeFields: ['quoteRow' => []]);
            $report = new Report();
            $value = ['4021' => ['type' => 'quoteRow', 'enabled' => true, $key => ['oops'], 'fields' => []]];
            $t = new MatrixTransformer($this->registry());

            $out = $t->import(new FieldDescriptor('craft\\fields\\Matrix', 'quotes'), $value, $resolver, $report);

            $this->assertArrayNotHasKey($key, $out['new1'], "an unusable '$key' must not be forwarded to Craft");
            $this->assertNotEmpty($report->warnings);
            $this->assertStringContainsString($key, $report->warnings[0]);
        }
    }

    /** Null and string titles and slugs pass through unchanged. */
    public function testNullAndStringBlockTitleAndSlugAreForwardedUnchanged(): void
    {
        $resolver = new FakeResolver(entryTypeFields: ['quoteRow' => []]);
        $report = new Report();
        $value = ['4021' => ['type' => 'quoteRow', 'enabled' => true, 'title' => null, 'slug' => 'row-1', 'collapsed' => false, 'fields' => []]];
        $t = new MatrixTransformer($this->registry());

        $out = $t->import(new FieldDescriptor('craft\\fields\\Matrix', 'quotes'), $value, $resolver, $report);

        $this->assertArrayHasKey('title', $out['new1']);
        $this->assertNull($out['new1']['title']);
        $this->assertSame('row-1', $out['new1']['slug']);
        $this->assertFalse($out['new1']['collapsed']);
        $this->assertSame([], $report->warnings);
    }

    public function testNonArrayBlockFieldsIsReportedRatherThanIterated(): void
    {
        $resolver = new FakeResolver(entryTypeFields: ['quoteRow' => []]);
        $report = new Report();
        $value = ['4021' => ['type' => 'quoteRow', 'enabled' => true, 'fields' => 'oops']];
        $t = new MatrixTransformer($this->registry());

        $out = $t->import(new FieldDescriptor('craft\\fields\\Matrix', 'quotes'), $value, $resolver, $report);

        $this->assertSame([], $out['new1']['fields']);
        $this->assertNotEmpty($report->warnings);
        $this->assertStringContainsString('fields', $report->warnings[0]);
    }
}
