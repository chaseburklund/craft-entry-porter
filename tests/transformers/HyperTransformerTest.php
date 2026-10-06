<?php

namespace chaseburklund\entryporter\tests\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\transformers\HyperTransformer;
use chaseburklund\entryporter\tests\Support\FakeResolver;
use PHPUnit\Framework\TestCase;

final class HyperTransformerTest extends TestCase
{
    public function testSupportsHyperFieldOnly(): void
    {
        $t = new HyperTransformer();
        $this->assertTrue($t->supports('verbb\\hyper\\fields\\HyperField'));
        $this->assertFalse($t->supports('craft\\fields\\PlainText'));
    }

    public function testExportSwapsElementLinkValueForRef(): void
    {
        $entryRef = Ref::make('entry', 'e-uid', ['section' => 'pages', 'slug' => 'about']);
        $resolver = new FakeResolver(elements: ['entry:31' => $entryRef]);
        $report = new Report();
        $value = [
            ['type' => 'verbb\\hyper\\links\\Entry', 'linkValue' => 31, 'linkSiteId' => 1, 'linkText' => 'About'],
            ['type' => 'verbb\\hyper\\links\\Url', 'linkValue' => 'https://x.com', 'linkSiteId' => null],
        ];
        $t = new HyperTransformer();
        $out = $t->export(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'itemLink'), $value, $resolver, $report);
        $this->assertSame($entryRef, $out[0]['linkValue']);
        $this->assertNull($out[0]['linkSiteId']);
        $this->assertSame('About', $out[0]['linkText']);
        $this->assertSame('https://x.com', $out[1]['linkValue']);
        $this->assertNotEmpty($report->warnings); // linkSiteId cleared
    }

    /** Formie form links use the 'form' kind. */
    public function testExportSwapsFormieFormLinkValueForRef(): void
    {
        $formRef = Ref::make('form', 'f-uid', ['handle' => 'contact']);
        $resolver = new FakeResolver(elements: ['form:12' => $formRef]);
        $report = new Report();
        $value = [['type' => 'verbb\\hyper\\links\\FormieForm', 'linkValue' => 12, 'linkSiteId' => null]];
        $t = new HyperTransformer();
        $out = $t->export(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'itemLink'), $value, $resolver, $report);
        $this->assertSame($formRef, $out[0]['linkValue']);
    }

    /** Hyper can store an element link's ID wrapped in a single-item array. */
    public function testExportUnwrapsArrayWrappedElementLinkValue(): void
    {
        $entryRef = Ref::make('entry', 'e-uid', ['section' => 'pages', 'slug' => 'about']);
        $resolver = new FakeResolver(elements: ['entry:31' => $entryRef]);
        $report = new Report();
        $value = [['type' => 'verbb\\hyper\\links\\Entry', 'linkValue' => [31], 'linkSiteId' => null]];
        $t = new HyperTransformer();
        $out = $t->export(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'itemLink'), $value, $resolver, $report);
        $this->assertSame($entryRef, $out[0]['linkValue']);
    }

    /**
     * An empty selection is normal: no lookup and no warning for it. The link's site ID is
     * still cleared, with its own warning.
     */
    public function testExportTreatsEmptySelectionAsNoSelectionWithoutSpuriousWarning(): void
    {
        $resolver = new FakeResolver();
        $report = new Report();
        $value = [['type' => 'verbb\\hyper\\links\\Entry', 'linkValue' => [], 'linkSiteId' => 4]];
        $t = new HyperTransformer();
        $out = $t->export(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'itemLink'), $value, $resolver, $report);
        $this->assertSame([], $out[0]['linkValue']);
        $this->assertNull($out[0]['linkSiteId']);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('linkSiteId', $report->warnings[0]);
    }

    /** An unrecognizable linkValue is kept as it is, with a warning. */
    public function testExportWarnsOnMalformedElementLinkValueAndLeavesItAsIs(): void
    {
        $resolver = new FakeResolver();
        $report = new Report();
        $value = [['type' => 'verbb\\hyper\\links\\Entry', 'linkValue' => 'not-an-id']];
        $t = new HyperTransformer();
        $out = $t->export(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'itemLink'), $value, $resolver, $report);
        $this->assertSame('not-an-id', $out[0]['linkValue']);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('itemLink', $report->warnings[0]);
    }

    public function testExportClearsLinkValueAndWarnsWhenResolverCannotDescribeElement(): void
    {
        $resolver = new FakeResolver(); // no elements registered
        $report = new Report();
        $value = [['type' => 'verbb\\hyper\\links\\Entry', 'linkValue' => 999]];
        $t = new HyperTransformer();
        $out = $t->export(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'itemLink'), $value, $resolver, $report);
        $this->assertNull($out[0]['linkValue']);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('999', $report->warnings[0]);
    }

    /**
     * Element link types the resolver cannot describe (here a Commerce product) are cleared
     * with a warning rather than keeping a source ID.
     */
    public function testExportHandlesUnsupportedButRealElementLinkKind(): void
    {
        $resolver = new FakeResolver(); // CraftResolver's describeElement() has no arm for 'product' either
        $report = new Report();
        $value = [['type' => 'verbb\\hyper\\links\\Product', 'linkValue' => 55]];
        $t = new HyperTransformer();
        $out = $t->export(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'itemLink'), $value, $resolver, $report);
        $this->assertNull($out[0]['linkValue']);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('product', $report->warnings[0]);
    }

    public function testExportWarnsOnNonArrayLinkAndPreservesIt(): void
    {
        $resolver = new FakeResolver();
        $report = new Report();
        $value = ['not-a-link-array'];
        $t = new HyperTransformer();
        $out = $t->export(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'itemLink'), $value, $resolver, $report);
        $this->assertSame(['not-a-link-array'], $out);
        $this->assertCount(1, $report->warnings);
        // The report says the link was kept, not skipped.
        $this->assertStringContainsString('copied verbatim', $report->warnings[0]);
    }

    /**
     * Custom fields inside a link are copied without remapping element references, and the
     * report says so.
     */
    public function testExportWarnsWhenLinkCarriesNestedCustomFieldContent(): void
    {
        $resolver = new FakeResolver();
        $report = new Report();
        $value = [[
            'type' => 'verbb\\hyper\\links\\Url',
            'linkValue' => 'https://x.com',
            'fields' => ['someLayoutUid' => [42]],
        ]];
        $t = new HyperTransformer();
        $out = $t->export(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'itemLink'), $value, $resolver, $report);
        $this->assertSame(['someLayoutUid' => [42]], $out[0]['fields']);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('nested custom-field content', $report->warnings[0]);
    }

    public function testExportNonArrayValuePassesThrough(): void
    {
        $t = new HyperTransformer();
        $report = new Report();
        $this->assertNull($t->export(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'x'), null, new FakeResolver(), $report));
    }

    public function testImportResolvesRefBackToId(): void
    {
        $entryRef = Ref::make('entry', 'e-uid', []);
        $resolver = new FakeResolver(resolutions: ['e-uid' => 77]);
        $report = new Report();
        $value = [['type' => 'verbb\\hyper\\links\\Entry', 'linkValue' => $entryRef, 'linkSiteId' => null]];
        $t = new HyperTransformer();
        $out = $t->import(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'itemLink'), $value, $resolver, $report);
        $this->assertSame(77, $out[0]['linkValue']);
    }

    public function testImportResolvesRefAndLeavesOtherLinksUntouched(): void
    {
        $entryRef = Ref::make('entry', 'e-uid', []);
        $resolver = new FakeResolver(resolutions: ['e-uid' => 77]);
        $report = new Report();
        $value = [
            ['type' => 'verbb\\hyper\\links\\Entry', 'linkValue' => $entryRef],
            ['type' => 'verbb\\hyper\\links\\Url', 'linkValue' => 'https://x.com'],
        ];
        $t = new HyperTransformer();
        $out = $t->import(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'itemLink'), $value, $resolver, $report);
        $this->assertSame(77, $out[0]['linkValue']);
        $this->assertSame('https://x.com', $out[1]['linkValue']);
    }

    public function testUnresolvedRefNullsLinkValue(): void
    {
        $entryRef = Ref::make('entry', 'gone-uid', []);
        $resolver = new FakeResolver();
        $report = new Report();
        $value = [['type' => 'verbb\\hyper\\links\\Entry', 'linkValue' => $entryRef]];
        $t = new HyperTransformer();
        $out = $t->import(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'itemLink'), $value, $resolver, $report);
        $this->assertNull($out[0]['linkValue']);
        $this->assertCount(1, $report->unresolved);
    }

    public function testImportWarnsOnNonArrayLinkAndPreservesIt(): void
    {
        $resolver = new FakeResolver();
        $report = new Report();
        $value = ['not-a-link-array'];
        $t = new HyperTransformer();
        $out = $t->import(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'itemLink'), $value, $resolver, $report);
        $this->assertSame(['not-a-link-array'], $out);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('copied verbatim', $report->warnings[0]);
    }

    public function testImportNonArrayValuePassesThrough(): void
    {
        $t = new HyperTransformer();
        $report = new Report();
        $this->assertNull($t->import(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'x'), null, new FakeResolver(), $report));
    }
}
