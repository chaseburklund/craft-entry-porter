<?php

namespace chaseburklund\entryporter\tests\services;

use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\services\Importer;
use PHPUnit\Framework\TestCase;

/**
 * Tests the import report's explanation of fields the payload did not include.
 *
 * A field can be absent because the exporter left it out (it cannot be ported, or its value
 * is derived) or because the source's field layout does not have it. The report explains
 * each case differently. A payload without a `stripped` key must produce the original
 * message, so older payloads are reported as before.
 */
final class AbsentFieldReportTest extends TestCase
{
    private const LAYOUT = ['heading', 'body', 'lenzLink', 'optimizedImages'];

    /** The message every absent handle used to get, and the one only "merely absent" gets now. */
    private const GENERIC = 'These fields exist in this layout but carried no value in the payload';

    /** An unportable field still has content on the source, which must be set by hand. */
    public function testAStrippedUnportableFieldIsReportedAsRemovedNotAsAbsent(): void
    {
        $warnings = Importer::absentFieldWarnings(
            self::LAYOUT,
            ['heading', 'body', 'optimizedImages'],
            ['lenzLink' => Registry::SKIP_UNPORTABLE],
        );

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('REMOVED from the copy by the source', $warnings[0]);
        $this->assertStringContainsString('not necessarily empty on the source', $warnings[0]);
        $this->assertStringContainsString('set them by hand: lenzLink.', $warnings[0]);
        $this->assertStringNotContainsString(self::GENERIC, $warnings[0],
            'the generic sentence is the defect: it reads as "the source had nothing here"');
    }

    /** A derived field needs no action: the target's own value is correct. */
    public function testAStrippedDerivedFieldIsReportedAsIntendedNotAsAProblem(): void
    {
        $warnings = Importer::absentFieldWarnings(
            self::LAYOUT,
            ['heading', 'body', 'lenzLink'],
            ['optimizedImages' => Registry::SKIP_DERIVED],
        );

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('on purpose', $warnings[0]);
        $this->assertStringContainsString('the intended outcome, not something to fix', $warnings[0]);
        $this->assertStringContainsString('optimizedImages.', $warnings[0]);
        $this->assertStringNotContainsString('by hand', $warnings[0]);
    }

    /** Each kind of absence gets its own message, naming only its own fields. */
    public function testTheThreeDispositionsAreReportedSeparatelyAndDoNotBorrowEachOthersHandles(): void
    {
        $warnings = Importer::absentFieldWarnings(
            self::LAYOUT,
            ['heading'],
            ['lenzLink' => Registry::SKIP_UNPORTABLE, 'optimizedImages' => Registry::SKIP_DERIVED],
        );

        $this->assertCount(3, $warnings);
        [$unportable, $derived, $absent] = $warnings;

        $this->assertStringContainsString('lenzLink', $unportable);
        $this->assertStringNotContainsString('optimizedImages', $unportable);
        $this->assertStringNotContainsString('body', $unportable);

        $this->assertStringContainsString('optimizedImages', $derived);
        $this->assertStringNotContainsString('lenzLink', $derived);
        $this->assertStringNotContainsString('body', $derived);

        $this->assertStringContainsString(self::GENERIC, $absent);
        $this->assertStringContainsString('body', $absent);
        $this->assertStringNotContainsString('lenzLink', $absent);
        $this->assertStringNotContainsString('optimizedImages', $absent);
    }

    /** Payloads without a `stripped` key are reported exactly as before. */
    public function testAPayloadWithNoStrippedKeyProducesTheOriginalMessage(): void
    {
        $expected = ['These fields exist in this layout but carried no value in the payload, so the draft keeps whatever the target already had for them (a new entry gets their defaults): body, lenzLink, optimizedImages.'];

        $this->assertSame($expected, Importer::absentFieldWarnings(self::LAYOUT, ['heading']));
        $this->assertSame($expected, Importer::absentFieldWarnings(self::LAYOUT, ['heading'], null));
    }

    /**
     * An unrecognized `stripped` value falls back to the generic message.
     *
     * @dataProvider unusableStrippedValues
     */
    public function testAnUnusableStrippedValueDegradesToTheGenericMessage(mixed $stripped): void
    {
        $warnings = Importer::absentFieldWarnings(self::LAYOUT, ['heading', 'body', 'optimizedImages'], $stripped);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString(self::GENERIC, $warnings[0]);
        $this->assertStringContainsString('lenzLink', $warnings[0]);
    }

    public static function unusableStrippedValues(): array
    {
        return [
            'a scalar where an object belongs' => ['unportable'],
            'an int' => [7],
            'a bool' => [true],
            'an empty object' => [[]],
            'a token this version has never heard of' => [['lenzLink' => 'someFutureReason']],
            'a non-string reason' => [['lenzLink' => ['unportable']]],
            'the reason nested one level too deep' => [['lenzLink' => ['reason' => 'unportable']]],
        ];
    }

    /**
     * Only handles that are actually absent here are reported, so a payload cannot add
     * arbitrary text to the report.
     */
    public function testStrippedEntriesForHandlesThatAreNotAbsentHereAreIgnored(): void
    {
        $warnings = Importer::absentFieldWarnings(
            self::LAYOUT,
            ['heading', 'body', 'optimizedImages'],
            [
                'heading' => Registry::SKIP_UNPORTABLE,          // present in the payload
                '<script>alert(1)</script>' => Registry::SKIP_UNPORTABLE, // not in this layout
                'lenzLink' => Registry::SKIP_UNPORTABLE,
            ],
        );

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('set them by hand: lenzLink.', $warnings[0]);
        $this->assertStringNotContainsString('heading', $warnings[0]);
        $this->assertStringNotContainsString('script', $warnings[0]);
    }

    /** Nothing absent, nothing to say -- including when `stripped` names things anyway. */
    public function testNothingAbsentProducesNoWarnings(): void
    {
        $this->assertSame([], Importer::absentFieldWarnings(self::LAYOUT, self::LAYOUT));
        $this->assertSame([], Importer::absentFieldWarnings(self::LAYOUT, self::LAYOUT, ['heading' => Registry::SKIP_UNPORTABLE]));
        $this->assertSame([], Importer::absentFieldWarnings([], []));
    }
}
