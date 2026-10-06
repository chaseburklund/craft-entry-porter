<?php

namespace chaseburklund\entryporter\tests\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\services\CraftResolver;
use chaseburklund\entryporter\tests\Support\FakeResolver;
use chaseburklund\entryporter\transformers\LinkTransformer;
use PHPUnit\Framework\TestCase;

/**
 * Craft's core Link field stores entry, asset and category links as reference tags
 * containing the source element's ID, such as `{entry:123@1:url}`.
 */
final class LinkTransformerTest extends TestCase
{
    private FieldDescriptor $field;

    protected function setUp(): void
    {
        $this->field = new FieldDescriptor('craft\\fields\\Link', 'ctaLink');
    }

    public function testSupportsCoreLinkFieldOnly(): void
    {
        $t = new LinkTransformer();
        $this->assertTrue($t->supports('craft\\fields\\Link'));
        $this->assertFalse($t->supports('craft\\fields\\Url'));
        $this->assertFalse($t->supports('lenz\\linkfield\\fields\\LinkField'));
    }

    public function testElementLinkBecomesARefAndComesBackWithTheTargetsOwnId(): void
    {
        $entryRef = Ref::make('entry', 'e-uid', ['section' => 'pages', 'type' => 'page', 'slug' => 'about']);
        $report = new Report();
        $t = new LinkTransformer();
        $value = [
            'value' => '{entry:123@1:url}',
            'type' => 'entry',
            'label' => 'About us',
            'target' => '_blank',
        ];

        $out = $t->export($this->field, $value, new FakeResolver(elements: ['entry:123' => $entryRef]), $report);

        $this->assertSame('link', $out['__portable']);
        $this->assertSame('{entry:__PORTER_ID__@1:url}', $out['value'], 'the source id must not survive into the payload');
        $this->assertSame($entryRef, $out['ref']);
        // Everything other than the ID is kept.
        $this->assertSame('About us', $out['label']);
        $this->assertSame('_blank', $out['target']);
        $this->assertSame('entry', $out['type']);
        $this->assertSame([], $report->warnings);

        $back = $t->import($this->field, $out, new FakeResolver(resolutions: ['e-uid' => 987]), $report);

        $this->assertSame('{entry:987@1:url}', $back['value']);
        $this->assertSame('entry', $back['type']);
        $this->assertSame('About us', $back['label']);
        // The portable envelope's own keys are removed.
        $this->assertArrayNotHasKey('__portable', $back);
        $this->assertArrayNotHasKey('ref', $back);
    }

    /** Asset and category links work too; the element type is matched case-insensitively. */
    public function testAssetAndCategoryLinksAndCasePreservation(): void
    {
        $t = new LinkTransformer();
        $assetRef = Ref::make('asset', 'a-uid', []);
        $out = $t->export(
            $this->field,
            ['value' => '{Asset:7:url}', 'type' => 'asset'],
            new FakeResolver(elements: ['asset:7' => $assetRef]),
            new Report(),
        );
        $this->assertSame('{Asset:__PORTER_ID__:url}', $out['value']);

        $catRef = Ref::make('category', 'c-uid', []);
        $out = $t->export(
            $this->field,
            ['value' => '{category:9@2:url}', 'type' => 'category'],
            new FakeResolver(elements: ['category:9' => $catRef]),
            new Report(),
        );
        $this->assertSame('{category:__PORTER_ID__@2:url}', $out['value']);
    }

    /** URL, email, phone and SMS links contain no ID and pass through unchanged. */
    public function testNonElementLinksPassThroughUntouched(): void
    {
        $t = new LinkTransformer();
        foreach ([
            ['value' => 'https://example.com/x', 'type' => 'url'],
            ['value' => 'mailto:a@example.com', 'type' => 'email'],
            ['value' => 'tel:+15551234567', 'type' => 'tel'],
        ] as $value) {
            $report = new Report();
            $this->assertSame($value, $t->export($this->field, $value, new FakeResolver(), $report));
            $this->assertSame([], $report->warnings);
            $this->assertSame($value, $t->import($this->field, $value, new FakeResolver(), $report));
        }
    }

    /** An empty Link field serializes to null. */
    public function testNullAndNonArrayValuesPassThrough(): void
    {
        $t = new LinkTransformer();
        $report = new Report();
        $this->assertNull($t->export($this->field, null, new FakeResolver(), $report));
        $this->assertNull($t->import($this->field, null, new FakeResolver(), $report));
        $this->assertSame('x', $t->export($this->field, 'x', new FakeResolver(), $report));
        $this->assertSame([], $report->warnings);
    }

    /** An element that cannot be described is dropped, never exported with its source ID. */
    public function testUndescribableElementLinkIsDroppedWithAWarning(): void
    {
        $t = new LinkTransformer();
        $report = new Report();

        $out = $t->export($this->field, ['value' => '{entry:999:url}', 'type' => 'entry'], new FakeResolver(), $report);

        $this->assertNull($out);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('ctaLink', $report->warnings[0]);
        $this->assertStringContainsString('999', $report->warnings[0]);
    }

    /** An unresolvable ref on import drops the link rather than writing a broken tag. */
    public function testUnresolvableRefDropsTheLinkAndReportsIt(): void
    {
        $t = new LinkTransformer();
        $report = new Report();
        $ref = Ref::make('entry', 'gone', []);

        $back = $t->import(
            $this->field,
            ['__portable' => 'link', 'value' => '{entry:__PORTER_ID__:url}', 'type' => 'entry', 'ref' => $ref],
            new FakeResolver(),
            $report,
        );

        $this->assertNull($back);
        $this->assertSame([$ref], $report->unresolved);
        $this->assertNotEmpty($report->warnings);
    }

    /**
     * Against the real resolver: a reference with an array `kind` passes Ref::isRef() but is
     * rejected by the resolver, and the warning that follows must not interpolate the array.
     */
    public function testMalformedRefIsHandledAgainstTheRealResolver(): void
    {
        $malformed = ['__portable' => 'ref', 'kind' => ['entry'], 'uid' => 'u'];
        $this->assertTrue(Ref::isRef($malformed), 'premise: Ref::isRef() lets this through');

        $report = new Report();
        $back = (new LinkTransformer())->import(
            $this->field,
            ['__portable' => 'link', 'value' => '{entry:__PORTER_ID__:url}', 'type' => 'entry', 'ref' => $malformed],
            new CraftResolver(),
            $report,
        );

        $this->assertNull($back);
        $this->assertSame([$malformed], $report->unresolved);
        $this->assertContains(
            "Field 'ctaLink': the linked element could not be resolved in the target; the link was dropped and the field was left empty rather than pointed at unrelated content.",
            $report->warnings,
        );
    }

    /** Non-string `type` and `value` members are refused rather than cast. */
    public function testMalformedEnvelopeMembersAreRefusedNotCoerced(): void
    {
        $t = new LinkTransformer();
        $ref = Ref::make('entry', 'e-uid', []);

        $report = new Report();
        $this->assertNull($t->import($this->field, [
            '__portable' => 'link', 'value' => ['x'], 'type' => 'entry', 'ref' => $ref,
        ], new FakeResolver(resolutions: ['e-uid' => 5]), $report));
        $this->assertStringContainsString('not a string', $report->warnings[0]);

        $report = new Report();
        $this->assertNull($t->import($this->field, [
            '__portable' => 'link', 'value' => '{entry:__PORTER_ID__:url}', 'type' => ['entry'], 'ref' => $ref,
        ], new FakeResolver(resolutions: ['e-uid' => 5]), $report));
        $this->assertStringContainsString("'type'", $report->warnings[0]);

        // Without the placeholder the link cannot be rebuilt around the target's ID.
        $report = new Report();
        $this->assertNull($t->import($this->field, [
            '__portable' => 'link', 'value' => '{entry:123:url}', 'type' => 'entry', 'ref' => $ref,
        ], new FakeResolver(resolutions: ['e-uid' => 5]), $report));
        $this->assertStringContainsString('placeholder', $report->warnings[0]);
    }

    /** A value that is not this transformer's envelope is none of its business. */
    public function testForeignEnvelopePassesThroughOnImport(): void
    {
        $t = new LinkTransformer();
        $value = ['value' => 'https://example.com', 'type' => 'url'];
        $this->assertSame($value, $t->import($this->field, $value, new FakeResolver(), new Report()));
    }
}
