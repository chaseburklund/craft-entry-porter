<?php

namespace chaseburklund\entryporter\tests\services;

use chaseburklund\entryporter\services\Exporter;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craft\events\CancelableEvent;
use PHPUnit\Framework\TestCase;

/**
 * Tests that exports include disabled Matrix, Super Table and Neo blocks. Their field queries
 * return enabled blocks only by default, which dropped disabled blocks from the payload.
 */
final class ExporterDisabledBlocksTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // Element queries are Yii objects. No Craft app is needed to build one.
        require_once dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
    }

    /**
     * A real EntryQuery, minus the custom-field behavior Craft generates at runtime.
     */
    private static function entryQuery(): EntryQuery
    {
        return new class(Entry::class) extends EntryQuery {
            public function behaviors(): array
            {
                return [];
            }
        };
    }

    private static function prepare(EntryQuery $query): EntryQuery
    {
        $event = new CancelableEvent();
        $event->sender = $query;
        Exporter::includeDisabledNestedElements($event);
        return $query;
    }

    public function testANestedEntryQueryOnTheDefaultStatusIncludesDisabledEntries(): void
    {
        $query = self::entryQuery();
        $query->fieldId = 12;
        $query->ownerId = 34;

        $this->assertNull(self::prepare($query)->status);
    }

    public function testAQueryScopedByPrimaryOwnerIsTreatedTheSame(): void
    {
        $query = self::entryQuery();
        $query->fieldId = 12;
        $query->primaryOwnerId = 34;

        $this->assertNull(self::prepare($query)->status);
    }

    /** Ordinary entry queries, such as the resolver's lookups, keep their status filter. */
    public function testAQueryThatIsNotForNestedEntriesIsLeftAlone(): void
    {
        $query = self::entryQuery();
        $before = $query->status;

        $this->assertSame(['live'], $before, 'premise: an entry query starts on the live status');
        $this->assertSame($before, self::prepare($query)->status);
    }

    /** A status chosen explicitly by the calling code is kept. */
    public function testAnExplicitStatusIsLeftAlone(): void
    {
        $query = self::entryQuery();
        $query->fieldId = 12;
        $query->ownerId = 34;
        $query->status('live');

        $this->assertSame('live', self::prepare($query)->status);
    }
}
