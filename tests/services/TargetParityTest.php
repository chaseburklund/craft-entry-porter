<?php

namespace chaseburklund\entryporter\tests\services;

use chaseburklund\entryporter\services\Importer;
use PHPUnit\Framework\TestCase;

/**
 * Tests Importer::targetParityError().
 *
 * An existing entry found by UID may be in a different section or of a different entry type
 * from the one the payload names. Craft would save only the fields in the existing entry's
 * own layout and silently drop the rest, so such an import is refused.
 */
final class TargetParityTest extends TestCase
{
    public function testMatchingSectionAndTypeIsNotAnError(): void
    {
        $this->assertNull(Importer::targetParityError(
            'news',
            'article',
            'news',
            'article',
            ['heading', 'body'],
            ['heading', 'body'],
        ));
    }

    /** The message names the fields that would be lost. */
    public function testDifferentEntryTypeIsRefusedAndNamesTheHandlesThatWouldBeLost(): void
    {
        $error = Importer::targetParityError(
            'news',
            'legacyArticle',
            'news',
            'article',
            ['heading', 'body', 'pullQuote'],
            ['heading', 'body'],
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString("'legacyArticle'", $error);
        $this->assertStringContainsString("'article'", $error);
        $this->assertStringContainsString('pullQuote', $error, 'the handle that would vanish must be named');
        $this->assertStringNotContainsString('heading', $error, 'handles that would survive are not losses');
        $this->assertStringContainsString('re-import', $error, 'the operator needs to be told what to do next');
    }

    /**
     * A type mismatch is refused even when no fields would be lost, since the entry would
     * keep the wrong type. The message must not claim that fields would be lost.
     */
    public function testDifferentTypeWithFullyOverlappingLayoutsIsStillRefusedWithoutClaimingLoss(): void
    {
        $error = Importer::targetParityError(
            'news',
            'legacyArticle',
            'news',
            'article',
            ['heading'],
            ['heading', 'body'],
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('does happen to exist', $error);
        $this->assertStringNotContainsString('silently discard', $error);
    }

    /** A UID that resolves into another section is not this payload's entry. */
    public function testDifferentSectionIsRefused(): void
    {
        $error = Importer::targetParityError('blog', 'article', 'news', 'article', ['body'], ['body']);

        $this->assertNotNull($error);
        $this->assertStringContainsString("'blog'", $error);
        $this->assertStringContainsString("'news'", $error);
    }

    /** A nested entry has no section; the message says so rather than showing ''. */
    public function testNestedEntryTargetIsRefusedAndDescribedAsSuch(): void
    {
        $error = Importer::targetParityError(null, 'imageBlock', 'news', 'article', ['body'], ['body']);

        $this->assertNotNull($error);
        $this->assertStringContainsString('nested entry', $error);
        $this->assertStringNotContainsString("section ''", $error);
    }

    /** An entry whose type no longer exists is a mismatch. */
    public function testUnresolvableTargetTypeIsRefused(): void
    {
        $error = Importer::targetParityError('news', null, 'news', 'article', ['body'], []);

        $this->assertNotNull($error);
        $this->assertStringContainsString('unresolvable', $error);
    }

    /** Both mismatched at once reads as one message naming both, not two half-truths. */
    public function testSectionAndTypeMismatchAreReportedTogether(): void
    {
        $error = Importer::targetParityError('blog', 'post', 'news', 'article', ['body'], ['body']);

        $this->assertNotNull($error);
        $this->assertStringContainsString("'blog'", $error);
        $this->assertStringContainsString("'post'", $error);
        $this->assertStringContainsString('; and ', $error);
    }
}
