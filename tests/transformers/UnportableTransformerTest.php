<?php

namespace chaseburklund\entryporter\tests\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\tests\Support\FakeResolver;
use chaseburklund\entryporter\transformers\UnportableTransformer;
use PHPUnit\Framework\TestCase;

/**
 * UnportableTransformer's class list is empty in production, so these tests supply their own
 * field class.
 */
final class UnportableTransformerTest extends TestCase
{
    private const CLASSES = [
        'vendor\\example\\fields\\IdBearingField' => 'a field type whose value carries the linked '
            . 'element\'s source-environment id (linkedId) in a shape this version cannot remap',
    ];

    private FieldDescriptor $field;

    protected function setUp(): void
    {
        $this->field = new FieldDescriptor('vendor\\example\\fields\\IdBearingField', 'assetLink');
    }

    private function transformer(): UnportableTransformer
    {
        return new UnportableTransformer(self::CLASSES);
    }

    public function testSupportsOnlyTheClassesItWasGiven(): void
    {
        $t = $this->transformer();
        $this->assertTrue($t->supports('vendor\\example\\fields\\IdBearingField'));
        $this->assertFalse($t->supports('craft\\fields\\Link'));
        $this->assertFalse($t->supports('craft\\fields\\PlainText'));
    }

    /** With an empty list, no field class is claimed. */
    public function testAnEmptyListClaimsNothingAtAll(): void
    {
        $t = new UnportableTransformer();
        $this->assertFalse($t->supports('vendor\\example\\fields\\IdBearingField'));
        $this->assertFalse($t->supports('lenz\\linkfield\\fields\\LinkField'));
        $this->assertFalse($t->supports(''));
    }

    /** The field is skipped, not copied, and the report explains why. */
    public function testExportRefusesTheFieldAndSaysWhy(): void
    {
        $serialized = '{"type":"entry","linkedId":4021,"linkedSiteId":1,"linkedTitle":"Rig 12"}';
        $report = new Report();

        $out = $this->transformer()->export($this->field, $serialized, new FakeResolver(), $report);

        $this->assertTrue(Registry::isSkip($out), 'the field must be excluded from the payload, not copied');
        $this->assertSame(Registry::SKIP_UNPORTABLE, Registry::skipReason($out),
            'the reason has to reach the payload, or the import leg can only say "carried no value"');
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('assetLink', $report->warnings[0]);
        $this->assertStringContainsString('linkedId', $report->warnings[0]);
        $this->assertStringContainsString('set it by hand', $report->warnings[0]);
    }

    /**
     * A payload could still carry a value for such a field. Nothing is written, and the
     * report says the target keeps its existing value.
     */
    public function testImportRefusesAndDescribesTheActualDisposition(): void
    {
        $report = new Report();

        $out = $this->transformer()->import($this->field, '{"linkedId":4021}', new FakeResolver(), $report);

        $this->assertTrue(Registry::isSkip($out));
        $this->assertSame(Registry::SKIP_UNPORTABLE, Registry::skipReason($out));
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('keeps whatever the target already had', $report->warnings[0]);
    }

    /** A class not in the list still gets an accurate message. */
    public function testAnUnlistedClassStillGetsAnHonestSentence(): void
    {
        $report = new Report();
        $this->transformer()->export(new FieldDescriptor('other\\Field', 'x'), null, new FakeResolver(), $report);
        $this->assertStringContainsString('a field type this version cannot port', $report->warnings[0]);
    }
}
