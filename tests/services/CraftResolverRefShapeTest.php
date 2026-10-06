<?php

namespace chaseburklund\entryporter\tests\services;

use craft\elements\Category;
use craft\elements\Entry;
use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\services\CraftResolver;
use chaseburklund\entryporter\tests\Support\RecordingResolver;
use chaseburklund\entryporter\transformers\RelationTransformer;
use PHPUnit\Framework\TestCase;

/**
 * Tests how CraftResolver handles malformed references.
 *
 * These tests run without a Craft app, which also shows that resolveRef() rejects a malformed
 * reference before it makes any Craft call.
 */
final class CraftResolverRefShapeTest extends TestCase
{
    private function wellFormedRef(): array
    {
        return Ref::make('entry', 'e-uid', ['section' => 'pages', 'type' => 'page', 'slug' => 'about']);
    }

    // --- refShapeError() ----------------------------------------------------------

    public function testWellFormedRefHasNoShapeError(): void
    {
        $this->assertNull(CraftResolver::refShapeError($this->wellFormedRef()));
    }

    /**
     * Every shape describeElement() produces must pass both checks, including a nested entry,
     * whose section is null.
     */
    public function testEveryRealRefShapePasses(): void
    {
        $refs = [
            Ref::make('entry', 'u', ['section' => 's', 'type' => 't', 'slug' => 'x'], ['title' => 'T']),
            // A nested entry has no section.
            Ref::make('entry', 'u', ['section' => null, 'type' => 'card', 'slug' => 'x'], ['title' => 'T']),
            Ref::make('asset', 'u', ['volume' => 'v', 'folderPath' => '', 'filename' => 'a.jpg'], ['url' => 'https://x/a.jpg', 'title' => 'A']),
            // An asset in a volume without public URLs.
            Ref::make('asset', 'u', ['volume' => 'v', 'folderPath' => '', 'filename' => 'a.jpg'], ['url' => null, 'title' => 'A']),
            Ref::make('category', 'u', ['group' => 'g', 'slug' => 'x'], ['title' => 'C']),
            Ref::make('user', 'u', ['email' => 'a@b.c']),
            Ref::make('form', 'u', ['handle' => 'contact']),
        ];
        foreach ($refs as $ref) {
            $this->assertNull(CraftResolver::refShapeError($ref), 'real ref shape should pass: ' . $ref['kind']);
            $this->assertNull(CraftResolver::refKeysError($ref), 'real ref keys should pass: ' . $ref['kind']);
        }
    }

    public function testAssetUrlAndEmptyFolderPathAreAccepted(): void
    {
        $ref = Ref::make('asset', 'u', ['volume' => 'v', 'folderPath' => '', 'filename' => 'a.jpg'], ['url' => 'https://x/a.jpg']);
        $this->assertNull(CraftResolver::refShapeError($ref));
    }

    public function testArrayUidIsRejected(): void
    {
        $ref = $this->wellFormedRef();
        $ref['uid'] = ['x'];
        $this->assertStringContainsString("'uid'", (string)CraftResolver::refShapeError($ref));
        $this->assertStringContainsString('array', (string)CraftResolver::refShapeError($ref));
    }

    public function testNonStringScalarUidIsRejected(): void
    {
        foreach ([42, 1.5, true] as $value) {
            $ref = $this->wellFormedRef();
            $ref['uid'] = $value;
            $this->assertNotNull(
                CraftResolver::refShapeError($ref),
                'uid ' . var_export($value, true) . ' should be rejected',
            );
        }
    }

    public function testArrayKindIsRejected(): void
    {
        $ref = $this->wellFormedRef();
        $ref['kind'] = ['entry'];
        $this->assertStringContainsString("'kind'", (string)CraftResolver::refShapeError($ref));
    }

    public function testMissingKindIsRejected(): void
    {
        $ref = $this->wellFormedRef();
        unset($ref['kind']);
        $this->assertSame("its 'kind' is missing", CraftResolver::refShapeError($ref));
    }

    public function testMissingKindAndUidIsRejected(): void
    {
        $this->assertNotNull(CraftResolver::refShapeError(['__portable' => 'ref']));
        $this->assertNotNull(CraftResolver::refShapeError([]));
    }

    public function testArrayUrlIsRejected(): void
    {
        $ref = Ref::make('asset', 'u', ['volume' => 'v', 'filename' => 'a.jpg']);
        $ref['url'] = ['https://x/a.jpg'];
        $this->assertStringContainsString("'url'", (string)CraftResolver::refShapeError($ref));
    }

    // --- refKeysError() -------------------------------------------------------------

    /** Natural keys are only used as a fallback, so bad keys are reported but not fatal. */
    public function testScalarKeysIsReportedByTheKeysPredicateAndIsNotFatal(): void
    {
        $ref = $this->wellFormedRef();
        $ref['keys'] = 'not-an-object';
        $this->assertNull(CraftResolver::refShapeError($ref), 'a malformed keys map must not be fatal');
        $this->assertStringContainsString("'keys'", (string)CraftResolver::refKeysError($ref));
    }

    public function testNonStringNaturalKeyValueIsReportedByTheKeysPredicate(): void
    {
        $cases = [
            ['entry', ['section' => ['pages'], 'type' => 'page', 'slug' => 'about'], 'section'],
            ['entry', ['section' => 'pages', 'type' => 'page', 'slug' => 99], 'slug'],
            ['form', ['handle' => ['contact']], 'handle'],
            ['user', ['email' => ['a@b.c']], 'email'],
            ['asset', ['volume' => 'v', 'filename' => ['a.jpg']], 'filename'],
        ];
        foreach ($cases as [$kind, $keys, $offender]) {
            $ref = Ref::make($kind, 'u', $keys);
            $error = CraftResolver::refKeysError($ref);
            $this->assertNotNull($error, "$kind ref with a non-string '$offender' should be reported");
            $this->assertStringContainsString($offender, $error);
            $this->assertNull(CraftResolver::refShapeError($ref), "$kind ref must still be resolvable by UID");
        }
    }

    /** Null keys are normal (a nested entry's section) and must not be reported. */
    public function testNullNaturalKeyValuesAreAcceptedSilently(): void
    {
        $ref = Ref::make('entry', 'nested-uid', ['section' => null, 'type' => 'card', 'slug' => 'a-card']);
        $this->assertNull(CraftResolver::refShapeError($ref));
        $this->assertNull(CraftResolver::refKeysError($ref));
    }

    // --- resolveRef() -----------------------------------------------------------------

    public function testResolveRefRejectsMalformedRefsWithoutTouchingCraft(): void
    {
        $shapes = [
            'array uid' => ['__portable' => 'ref', 'kind' => 'entry', 'uid' => ['x']],
            'int uid' => ['__portable' => 'ref', 'kind' => 'entry', 'uid' => 42],
            'array kind' => ['__portable' => 'ref', 'kind' => ['entry'], 'uid' => 'u'],
            'missing both' => ['__portable' => 'ref'],
            'array url' => ['__portable' => 'ref', 'kind' => 'asset', 'uid' => 'u', 'url' => ['https://x/a.jpg']],
        ];

        foreach ($shapes as $label => $ref) {
            $resolver = new CraftResolver();
            $report = new Report();
            $this->assertNull($resolver->resolveRef($ref, $report), "$label should resolve to null");
            $this->assertCount(1, $report->warnings, "$label should produce exactly one warning");
            $this->assertStringContainsString('malformed', $report->warnings[0]);
            $this->assertSame([$ref], $report->unresolved, "$label should be recorded as unresolved");
        }
    }

    /**
     * A malformed reference inside a relation field passes Ref::isRef(), which checks only
     * that the keys are present, and is caught by resolveRef().
     */
    public function testMalformedRefArrivingViaRelationFieldIsHandled(): void
    {
        $malformed = ['__portable' => 'ref', 'kind' => 'entry', 'uid' => ['x']];
        $transformer = new RelationTransformer();
        $resolver = new CraftResolver();
        $report = new Report();

        $result = $transformer->import(
            new FieldDescriptor('craft\\fields\\Entries', 'related'),
            [$malformed],
            $resolver,
            $report,
        );

        $this->assertSame([], $result, 'a malformed ref must contribute no id');
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('malformed', $report->warnings[0]);
        $this->assertSame([$malformed], $report->unresolved);
    }

    // --- Resolution order -------------------------------------------------------------

    public function testNestedEntryRefWithNullSectionResolvesByUid(): void
    {
        $resolver = new RecordingResolver();
        $resolver->idForUid = 4242;
        $report = new Report();
        $ref = Ref::make('entry', 'nested-uid', ['section' => null, 'type' => 'card', 'slug' => 'a-card']);

        $this->assertSame(4242, $resolver->resolveRef($ref, $report));
        $this->assertSame([['nested-uid', Entry::class]], $resolver->uidLookups);
        $this->assertSame([], $report->warnings, 'a legitimate nested-entry ref must not warn');
        $this->assertSame([], $report->unresolved);
    }

    public function testMalformedKeysDegradeToUidOnlyResolutionWithAWarning(): void
    {
        $resolver = new RecordingResolver();
        $resolver->idForUid = 77;
        $report = new Report();
        $ref = Ref::make('entry', 'e-uid', ['section' => ['pages'], 'type' => 'page', 'slug' => 'about']);

        $this->assertSame(77, $resolver->resolveRef($ref, $report));
        $this->assertSame([['e-uid', Entry::class]], $resolver->uidLookups);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('section', $report->warnings[0]);
        $this->assertStringContainsString('UID', $report->warnings[0]);
        $this->assertSame([], $report->unresolved, 'a ref that resolved is not unresolved');
    }

    public function testMalformedKeysWithNoUidMatchIsReportedUnresolved(): void
    {
        $resolver = new RecordingResolver();
        $resolver->idForUid = null;
        $report = new Report();
        $ref = Ref::make('category', 'c-uid', ['group' => 'topics', 'slug' => ['x']]);

        $this->assertNull($resolver->resolveRef($ref, $report));
        // The UID lookup is still attempted.
        $this->assertSame([['c-uid', Category::class]], $resolver->uidLookups);
        $this->assertSame([$ref], $report->unresolved);
        $this->assertSame([], $report->resolvedByFallback, 'the natural-key fallback must not have been attempted');
    }

    /**
     * With one unusable key, no natural-key query is run at all, even though the keys the
     * query would use are valid. (Running one would need Craft and fail here.)
     */
    public function testUnusableKeysSkipTheNaturalKeyQueryEntirely(): void
    {
        $resolver = new RecordingResolver();
        $resolver->idForUid = null;
        $report = new Report();
        $ref = Ref::make('category', 'c-uid', ['group' => 'topics', 'slug' => 'news', 'bogus' => ['x']]);

        $this->assertNull($resolver->resolveRef($ref, $report));
        $this->assertSame([['c-uid', Category::class]], $resolver->uidLookups);
        $this->assertSame([$ref], $report->unresolved);
        $this->assertSame([], $report->resolvedByFallback);
    }

    public function testRefIsRefAcceptsTheMalformedRefThatTheChokepointCatches(): void
    {
        $this->assertTrue(Ref::isRef(['__portable' => 'ref', 'kind' => 'entry', 'uid' => ['x']]));
        $this->assertNotNull(CraftResolver::refShapeError(['__portable' => 'ref', 'kind' => 'entry', 'uid' => ['x']]));
    }
}
