<?php

namespace chaseburklund\entryporter\tests\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\transformers\FallbackTransformer;
use chaseburklund\entryporter\transformers\KnownSafeTransformer;
use chaseburklund\entryporter\transformers\SeoSettingsTransformer;
use chaseburklund\entryporter\transformers\SkipTransformer;
use chaseburklund\entryporter\tests\Support\FakeResolver;
use PHPUnit\Framework\TestCase;

final class PolicyTransformersTest extends TestCase
{
    public function testKnownSafePassesThroughSilently(): void
    {
        $t = new KnownSafeTransformer();
        $this->assertTrue($t->supports('craft\\fields\\PlainText'));
        $this->assertTrue($t->supports('craft\\fields\\Lightswitch'));
        $this->assertTrue($t->supports('craft\\fields\\Dropdown'));
        $this->assertTrue($t->supports('craft\\fields\\Table'));
        $this->assertFalse($t->supports('craft\\fields\\Assets'));
        $report = new Report();
        $out = $t->export(new FieldDescriptor('craft\\fields\\PlainText', 'x'), 'v', new FakeResolver(), $report);
        $this->assertSame('v', $out);
        $this->assertSame([], $report->warnings);
    }

    /** Skipped in both directions, with the `derived` reason. */
    public function testSkipReturnsSentinelBothWaysAndSaysItIsDerivedData(): void
    {
        $t = new SkipTransformer(['nystudio107\\imageoptimize\\fields\\OptimizedImages']);
        $this->assertTrue($t->supports('nystudio107\\imageoptimize\\fields\\OptimizedImages'));
        $report = new Report();
        $field = new FieldDescriptor('nystudio107\\imageoptimize\\fields\\OptimizedImages', 'opt');

        foreach ([$t->export($field, ['x'], new FakeResolver(), $report), $t->import($field, ['x'], new FakeResolver(), $report)] as $out) {
            $this->assertTrue(Registry::isSkip($out));
            $this->assertSame(Registry::SKIP_DERIVED, Registry::skipReason($out));
        }
    }

    public function testSeoSettingsPassesThroughWithWarning(): void
    {
        $t = new SeoSettingsTransformer();
        $this->assertTrue($t->supports('nystudio107\\seomatic\\fields\\SeoSettings'));
        $report = new Report();
        $val = ['metaBundleSettings' => []];
        $this->assertSame($val, $t->export(new FieldDescriptor('nystudio107\\seomatic\\fields\\SeoSettings', 'seo'), $val, new FakeResolver(), $report));
        $this->assertCount(1, $report->warnings);
    }

    public function testFallbackWarnsAndPassesThrough(): void
    {
        $t = new FallbackTransformer();
        $this->assertTrue($t->supports('anything\\At\\All'));
        $report = new Report();
        $out = $t->export(new FieldDescriptor('some\\Custom\\Field', 'x'), 7, new FakeResolver(), $report);
        $this->assertSame(7, $out);
        $this->assertStringContainsString('some\\Custom\\Field', $report->warnings[0]);
        // The import side is silent; the warning was given on export.
        $report2 = new Report();
        $this->assertSame(7, $t->import(new FieldDescriptor('some\\Custom\\Field', 'x'), 7, new FakeResolver(), $report2));
        $this->assertSame([], $report2->warnings);
    }
}
