<?php

namespace chaseburklund\entryporter\tests\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\tests\Support\FakeResolver;
use chaseburklund\entryporter\transformers\LenzLinkTransformer;
use PHPUnit\Framework\TestCase;

/**
 * Typed Link field values are JSON strings, with a nested JSON string in `payload`. A null
 * value does not clear the field, so every case that drops a link expects the empty array
 * that does.
 */
final class LenzLinkTransformerTest extends TestCase
{
    private FieldDescriptor $field;

    /** A populated entry link, as the field serializes it. */
    private const SERIALIZED = '{"linkedId":67,"linkedSiteId":1,"linkedTitle":null,"linkedUrl":null,"payload":"{\"customText\":\"Lenz link to HX Ref Alpha\"}","type":"entry"}';

    protected function setUp(): void
    {
        $this->field = new FieldDescriptor('lenz\\linkfield\\fields\\LinkField', 'assetLink');
    }

    public function testSupportsTheTypedLinkFieldOnly(): void
    {
        $t = new LenzLinkTransformer();
        $this->assertTrue($t->supports('lenz\\linkfield\\fields\\LinkField'));
        $this->assertFalse($t->supports('craft\\fields\\Link'));
        $this->assertFalse($t->supports('verbb\\hyper\\fields\\HyperField'));
    }

    public function testElementLinkTravelsAsARefAndComesBackOnTheTargetsOwnId(): void
    {
        $ref = Ref::make('entry', 'a8163eb3', ['section' => 'pages', 'type' => 'page', 'slug' => 'hx-ref-alpha']);
        $t = new LenzLinkTransformer();
        $report = new Report();

        $portable = $t->export($this->field, self::SERIALIZED, new FakeResolver(elements: ['entry:67' => $ref]), $report);

        $this->assertSame('lenzlink', $portable['__portable']);
        $this->assertSame($ref, $portable['ref']);
        $this->assertNull($portable['link']['linkedId'], 'the source element id must not survive into the payload');
        $this->assertSame('entry', $portable['link']['type']);
        $this->assertSame('{"customText":"Lenz link to HX Ref Alpha"}', $portable['link']['payload'],
            'payload must stay a JSON STRING: a nested array makes normalizeValue() throw "Invalid JSON data."');
        $this->assertStringNotContainsString('67', json_encode($portable['link']),
            'no member of the exported link may still carry the source id');
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('and were not copied: linkedSiteId', $report->warnings[0]);

        // The target holds the same UID under a different ID.
        $report = new Report();
        $out = $t->import($this->field, $portable, new FakeResolver(resolutions: ['a8163eb3' => 65]), $report);

        $this->assertSame(65, $out['linkedId']);
        $this->assertSame('entry', $out['type']);
        $this->assertSame('{"customText":"Lenz link to HX Ref Alpha"}', $out['payload']);
        $this->assertNull($out['linkedSiteId'], 'the source site id must not be written on the target');
        $this->assertNull($out['linkedTitle']);
        $this->assertNull($out['linkedUrl']);
        $this->assertSame([], $report->warnings, 'a clean round trip must say nothing');
    }

    /** A URL link keeps its address in `linkedUrl`, which must not be cleared. */
    public function testNonElementLinkTravelsByteIdentically(): void
    {
        $t = new LenzLinkTransformer();
        $report = new Report();
        $url = '{"linkedId":null,"linkedSiteId":null,"linkedTitle":null,"linkedUrl":"https://example.com/a","payload":"{\"customText\":\"A url\"}","type":"url"}';

        $out = $t->export($this->field, $url, new FakeResolver(), $report);
        $this->assertSame($url, $out, 'a link with no element in it has nothing to remap');
        $this->assertSame([], $report->warnings);

        $back = $t->import($this->field, $out, new FakeResolver(), $report);
        $this->assertSame($url, $back, 'and it must survive the import leg untouched');
        $this->assertSame([], $report->warnings);
    }

    public function testAnUndescribableElementClearsTheFieldRatherThanReturningNull(): void
    {
        $report = new Report();
        $out = (new LenzLinkTransformer())->export($this->field, self::SERIALIZED, new FakeResolver(), $report);

        $this->assertSame([], $out, 'null does NOT clear a Typed Link Field; [] does');
        $this->assertNotNull($out);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('assetLink', $report->warnings[0]);
        $this->assertStringContainsString('entry #67', $report->warnings[0]);
        $this->assertStringContainsString('clear whatever link the target currently has', $report->warnings[0]);
    }

    public function testAnUnresolvableRefClearsTheFieldAndSaysSo(): void
    {
        $ref = Ref::make('entry', 'gone', []);
        $portable = ['__portable' => 'lenzlink', 'link' => ['type' => 'entry', 'linkedId' => null], 'ref' => $ref];
        $report = new Report();

        $out = (new LenzLinkTransformer())->import($this->field, $portable, new FakeResolver(), $report);

        $this->assertSame([], $out);
        $this->assertSame([$ref], $report->unresolved);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('cleared rather than left pointing at unrelated content', $report->warnings[0]);
    }

    /** A raw stored value with a source ID and no reference is never written through. */
    public function testARawLinkedIdWithNoRefIsClearedNotWritten(): void
    {
        $report = new Report();
        $out = (new LenzLinkTransformer())->import($this->field, self::SERIALIZED, new FakeResolver(), $report);

        $this->assertSame([], $out, 'a raw source id must never reach the target');
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('raw source-environment element id', $report->warnings[0]);
    }

    public function testANumericStringLinkedIdIsTreatedAsAnElementId(): void
    {
        $report = new Report();
        $out = (new LenzLinkTransformer())->import($this->field, ['type' => 'entry', 'linkedId' => '67'], new FakeResolver(), $report);

        $this->assertSame([], $out);
        $this->assertStringContainsString('raw source-environment element id', $report->warnings[0]);
    }

    public function testTheClearSentinelPassesThroughImportSilently(): void
    {
        $report = new Report();
        $out = (new LenzLinkTransformer())->import($this->field, [], new FakeResolver(), $report);

        $this->assertSame([], $out);
        $this->assertSame([], $report->warnings);
    }

    public function testANullValueIsClearedRatherThanWrittenThrough(): void
    {
        $report = new Report();
        $out = (new LenzLinkTransformer())->import($this->field, null, new FakeResolver(), $report);

        $this->assertSame([], $out, 'writing null would reload and RETAIN the target\'s stored link');
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('cleared rather than left pointing at whatever the target already had', $report->warnings[0]);
    }

    /** The field's link models have typed properties, so an array member would throw. */
    public function testANonScalarMemberIsDroppedRatherThanHandedToATypedProperty(): void
    {
        $ref = Ref::make('entry', 'u1', []);
        $portable = ['__portable' => 'lenzlink', 'link' => [
            'type' => 'entry', 'linkedId' => null, 'customText' => ['an', 'array'],
        ], 'ref' => $ref];
        $report = new Report();

        $out = (new LenzLinkTransformer())->import($this->field, $portable, new FakeResolver(resolutions: ['u1' => 9]), $report);

        $this->assertSame(9, $out['linkedId']);
        $this->assertArrayNotHasKey('customText', $out);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString("'customText' is a array", $report->warnings[0]);
    }

    /**
     * A `payload` that is not a JSON object string makes the field throw, so it is dropped
     * and the rest of the link is written.
     *
     * @dataProvider badPayloads
     */
    public function testABadPayloadMemberIsDroppedAndTheLinkStillLands(mixed $payload, string $expectSaid): void
    {
        $ref = Ref::make('entry', 'u1', []);
        $portable = ['__portable' => 'lenzlink', 'link' => ['type' => 'entry', 'payload' => $payload], 'ref' => $ref];
        $report = new Report();

        $out = (new LenzLinkTransformer())->import($this->field, $portable, new FakeResolver(resolutions: ['u1' => 9]), $report);

        $this->assertSame(9, $out['linkedId'], 'the link itself must still land on the right element');
        $this->assertArrayNotHasKey('payload', $out);
        $this->assertStringContainsString($expectSaid, implode(' ', $report->warnings));
    }

    public static function badPayloads(): array
    {
        return [
            'nested array (throws "Invalid JSON data.")' => [['customText' => 'x'], "'payload' is a array"],
            'unparseable string (throws "Syntax error")' => ['not json', "'payload' is not a JSON object"],
            'a bare int' => [5, "'payload' is not a JSON object"],
        ];
    }

    /** Site IDs differ between installs and cannot be remapped, so site links are flagged. */
    public function testASiteLinkIsPreservedButFlagged(): void
    {
        $report = new Report();
        $site = '{"linkedId":null,"linkedSiteId":2,"linkedTitle":null,"linkedUrl":null,"payload":null,"type":"site"}';

        $out = (new LenzLinkTransformer())->export($this->field, $site, new FakeResolver(), $report);

        $this->assertSame($site, $out);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('points at a SITE rather than an element', $report->warnings[0]);
    }

    public function testTheSourcesCachedTitleAndUrlAreDroppedAndReported(): void
    {
        $ref = Ref::make('entry', 'u1', []);
        $cached = '{"linkedId":67,"linkedSiteId":3,"linkedTitle":"HX Ref Alpha","linkedUrl":"https://stage.example.com/a","payload":null,"type":"entry"}';
        $report = new Report();

        $out = (new LenzLinkTransformer())->export($this->field, $cached, new FakeResolver(elements: ['entry:67' => $ref]), $report);

        $this->assertNull($out['link']['linkedSiteId']);
        $this->assertNull($out['link']['linkedTitle']);
        $this->assertNull($out['link']['linkedUrl']);
        $this->assertStringNotContainsString('stage.example.com', json_encode($out));
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('and were not copied: linkedSiteId, linkedTitle, linkedUrl', $report->warnings[0]);
    }

    /**
     * A value that cannot be read cannot be checked for IDs, so it is cleared in both
     * directions.
     *
     * @dataProvider unreadableValues
     */
    public function testAnUnreadableValueIsClearedOnBothLegs(mixed $value): void
    {
        $t = new LenzLinkTransformer();

        $report = new Report();
        $this->assertSame([], $t->export($this->field, $value, new FakeResolver(), $report));
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('assetLink', $report->warnings[0]);

        $report = new Report();
        $this->assertSame([], $t->import($this->field, $value, new FakeResolver(), $report));
        $this->assertCount(1, $report->warnings);
    }

    public static function unreadableValues(): array
    {
        return [
            'a string that is not JSON' => ['just text'],
            'a JSON scalar' => ['5'],
            'an int' => [5],
            'a bool' => [true],
        ];
    }
}
