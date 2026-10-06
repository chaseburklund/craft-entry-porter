<?php

namespace chaseburklund\entryporter\tests\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\tests\Support\FakeResolver;
use chaseburklund\entryporter\transformers\SimpleMapTransformer;
use PHPUnit\Framework\TestCase;

final class SimpleMapTransformerTest extends TestCase
{
    private FieldDescriptor $field;

    /** A map value as simplemap 5 serializes it, including its record's IDs. */
    private const STORED = [
        'id' => 24,
        'ownerId' => 295307,
        'ownerSiteId' => 1,
        'fieldId' => 27,
        'zoom' => 15,
        'distance' => null,
        'lat' => 29.9496125,
        'lng' => -90.0699355,
        'address' => '650 Poydras Street, New Orleans',
        'parts' => ['city' => 'New Orleans', 'state' => 'LA', 'country' => 'United States'],
        'what3words' => null,
    ];

    protected function setUp(): void
    {
        $this->field = new FieldDescriptor('ether\\simplemap\\fields\\MapField', 'address');
    }

    public function testSupportsTheMapFieldOnly(): void
    {
        $t = new SimpleMapTransformer();
        $this->assertTrue($t->supports('ether\\simplemap\\fields\\MapField'));
        $this->assertFalse($t->supports('craft\\fields\\PlainText'));
    }

    public function testExportKeepsTheLocationAndDropsTheSourceIds(): void
    {
        $report = new Report();
        $out = (new SimpleMapTransformer())->export($this->field, self::STORED, new FakeResolver(), $report);

        foreach (['id', 'ownerId', 'ownerSiteId', 'fieldId'] as $key) {
            $this->assertArrayNotHasKey($key, $out);
        }
        $this->assertSame(29.9496125, $out['lat']);
        $this->assertSame(-90.0699355, $out['lng']);
        $this->assertSame(15, $out['zoom']);
        $this->assertSame('650 Poydras Street, New Orleans', $out['address']);
        $this->assertSame(self::STORED['parts'], $out['parts']);
        $this->assertSame([], $report->warnings);
    }

    /** Payloads exported before this transformer existed still carry the IDs. */
    public function testImportDropsTheSourceIdsToo(): void
    {
        $report = new Report();
        $out = (new SimpleMapTransformer())->import($this->field, self::STORED, new FakeResolver(), $report);

        $this->assertArrayNotHasKey('id', $out);
        $this->assertArrayNotHasKey('ownerId', $out);
        $this->assertSame(29.9496125, $out['lat']);
        $this->assertSame([], $report->warnings);
    }

    public function testNonArrayValuesPassThrough(): void
    {
        $t = new SimpleMapTransformer();
        $report = new Report();
        $this->assertNull($t->export($this->field, null, new FakeResolver(), $report));
        $this->assertSame('x', $t->import($this->field, 'x', new FakeResolver(), $report));
    }
}
