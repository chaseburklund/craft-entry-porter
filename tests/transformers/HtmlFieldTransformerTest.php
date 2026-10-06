<?php

namespace chaseburklund\entryporter\tests\transformers;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\services\CraftResolver;
use chaseburklund\entryporter\transformers\HtmlFieldTransformer;
use chaseburklund\entryporter\tests\Support\FakeResolver;
use PHPUnit\Framework\TestCase;

final class HtmlFieldTransformerTest extends TestCase
{
    private FieldDescriptor $field;

    protected function setUp(): void
    {
        $this->field = new FieldDescriptor('craft\\ckeditor\\Field', 'body');
    }

    public function testSupportsBothHtmlFieldSubclassesAndNothingElse(): void
    {
        $t = new HtmlFieldTransformer();
        $this->assertTrue($t->supports('craft\\ckeditor\\Field'));
        $this->assertTrue($t->supports('craft\\redactor\\Field'));
        $this->assertFalse($t->supports('craft\\fields\\PlainText'));
        // The abstract base class is not claimed, so untested subclasses are not either.
        $this->assertFalse($t->supports('craft\\htmlfield\\HtmlField'));
    }

    public function testRedactorContentIsRemappedAndItsMessagesSayRedactor(): void
    {
        $field = new FieldDescriptor('craft\\redactor\\Field', 'itemCopy');
        $entryRef = Ref::make('entry', 'e-uid', []);
        $resolver = new FakeResolver(elements: ['entry:123' => $entryRef]);
        $report = new Report();
        $t = new HtmlFieldTransformer();

        $out = $t->export($field, '<p>See <a href="{entry:123@1:url}">it</a>.</p>', $resolver, $report);

        $this->assertIsArray($out, 'a Redactor ref tag must become a portable envelope, not pass through');
        $this->assertStringNotContainsString('123', $out['markup']);
        $this->assertSame(['ref1' => $entryRef], $out['refs']);

        $back = $t->import($field, $out, new FakeResolver(resolutions: ['e-uid' => 777]), new Report());
        $this->assertSame('<p>See <a href="{entry:777@1:url}">it</a>.</p>', $back);

        $undescribable = new Report();
        $t->export($field, '<p>{entry:999:url}</p>', new FakeResolver(), $undescribable);
        $this->assertStringContainsString('Redactor', $undescribable->warnings[0]);
        $this->assertStringNotContainsString('CKEditor', $undescribable->warnings[0]);
    }

    public function testTagReferenceTagIsPortable(): void
    {
        $tagRef = Ref::make('tag', 't-uid', ['group' => 'topics', 'title' => 'Design']);
        $resolver = new FakeResolver(elements: ['tag:5' => $tagRef]);
        $report = new Report();
        $t = new HtmlFieldTransformer();

        $out = $t->export($this->field, '<p>See {tag:5:title}.</p>', $resolver, $report);

        $this->assertIsArray($out);
        $this->assertSame('<p>See {tag:__PORTER_ref1__:title}.</p>', $out['markup']);
        $this->assertSame(['ref1' => $tagRef], $out['refs']);
    }

    /** Payloads written before the marker was renamed to 'htmlfield' still import. */
    public function testLegacyCkeditorMarkerStillImports(): void
    {
        $portable = [
            '__portable' => 'ckeditor',
            'markup' => '<p>See {entry:__PORTER_ref1__:url}.</p>',
            'refs' => ['ref1' => Ref::make('entry', 'e-uid', [])],
        ];
        $report = new Report();
        $t = new HtmlFieldTransformer();

        $back = $t->import($this->field, $portable, new FakeResolver(resolutions: ['e-uid' => 31]), $report);

        $this->assertSame('<p>See {entry:31:url}.</p>', $back);
    }

    public function testPlainMarkupPassesThroughAsString(): void
    {
        $t = new HtmlFieldTransformer();
        $report = new Report();
        $out = $t->export($this->field, '<p>Hello</p>', new FakeResolver(), $report);
        $this->assertSame('<p>Hello</p>', $out);
        $this->assertSame('<p>Hello</p>', $t->import($this->field, '<p>Hello</p>', new FakeResolver(), $report));
    }

    public function testReferenceTagsBecomePortableRefsAndBack(): void
    {
        $entryRef = Ref::make('entry', 'e-uid', []);
        $resolver = new FakeResolver(elements: ['entry:123' => $entryRef]);
        $report = new Report();
        $t = new HtmlFieldTransformer();
        $out = $t->export($this->field, '<p>See {entry:123@1:url}.</p>', $resolver, $report);
        $this->assertSame('htmlfield', $out['__portable']);
        $this->assertSame('<p>See {entry:__PORTER_ref1__@1:url}.</p>', $out['markup']);
        $this->assertSame(['ref1' => $entryRef], $out['refs']);

        $back = $t->import($this->field, $out, new FakeResolver(resolutions: ['e-uid' => 456]), new Report());
        $this->assertSame('<p>See {entry:456@1:url}.</p>', $back);
    }

    /** Craft allows the site to be given as a handle or UUID as well as an ID. */
    public function testReferenceTagWithSiteHandleIsStillPortable(): void
    {
        $entryRef = Ref::make('entry', 'e-uid', []);
        $resolver = new FakeResolver(elements: ['entry:123' => $entryRef]);
        $report = new Report();
        $t = new HtmlFieldTransformer();
        $out = $t->export($this->field, '<p>See {entry:123@primarySite:url}.</p>', $resolver, $report);
        $this->assertIsArray($out);
        $this->assertSame('<p>See {entry:__PORTER_ref1__@primarySite:url}.</p>', $out['markup']);
        $this->assertSame(['ref1' => $entryRef], $out['refs']);
    }

    public function testUserReferenceTagBecomesPortable(): void
    {
        $userRef = Ref::make('user', 'u-uid', []);
        $resolver = new FakeResolver(elements: ['user:5' => $userRef]);
        $report = new Report();
        $t = new HtmlFieldTransformer();
        $out = $t->export($this->field, '<p>By {user:5:url}.</p>', $resolver, $report);
        $this->assertIsArray($out);
        $this->assertSame('<p>By {user:__PORTER_ref1__:url}.</p>', $out['markup']);
        $this->assertSame(['ref1' => $userRef], $out['refs']);
    }

    public function testUnresolvedRefTagIsRemovedOnImport(): void
    {
        $portable = [
            '__portable' => 'htmlfield',
            'markup' => '<p>See {entry:__PORTER_ref1__:url} now.</p>',
            'refs' => ['ref1' => Ref::make('entry', 'gone', [])],
        ];
        $report = new Report();
        $t = new HtmlFieldTransformer();
        $back = $t->import($this->field, $portable, new FakeResolver(), $report);
        $this->assertSame('<p>See  now.</p>', $back);
        $this->assertCount(1, $report->unresolved);
        $this->assertNotEmpty($report->warnings);
    }

    /** A source ID left in the markup would link to an unrelated element on the target. */
    public function testUndescribableReferenceTagIsRemovedOnExportWithWarning(): void
    {
        $t = new HtmlFieldTransformer();
        $report = new Report();
        $out = $t->export($this->field, '<p>See {entry:999@1:url}.</p>', new FakeResolver(), $report);
        $outMarkup = is_array($out) ? $out['markup'] : $out;
        $this->assertStringNotContainsString('999', $outMarkup);
        $this->assertSame('<p>See .</p>', $outMarkup);
        $this->assertNotEmpty($report->warnings);
    }

    public function testEntryCardsAreStrippedWithWarning(): void
    {
        $t = new HtmlFieldTransformer();
        $report = new Report();
        $markup = '<p>a</p><craft-entry data-entry-id="9"></craft-entry><p>b</p>';
        $out = $t->export($this->field, $markup, new FakeResolver(), $report);
        $outMarkup = is_array($out) ? $out['markup'] : $out;
        $this->assertStringNotContainsString('craft-entry', $outMarkup);
        $this->assertSame('<p>a</p><p>b</p>', $outMarkup);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('9', $report->warnings[0]);
    }

    /** The report removes duplicate messages, so each card's warning must name its entry. */
    public function testMultipleDistinctEntryCardsEachProduceASeparateWarning(): void
    {
        $t = new HtmlFieldTransformer();
        $report = new Report();
        $markup = '<craft-entry data-entry-id="9"></craft-entry><craft-entry data-entry-id="10"></craft-entry>';
        $t->export($this->field, $markup, new FakeResolver(), $report);
        $this->assertCount(2, $report->warnings);
    }

    public function testNonStringValuesPassThrough(): void
    {
        $t = new HtmlFieldTransformer();
        $report = new Report();
        $this->assertNull($t->export($this->field, null, new FakeResolver(), $report));
        $this->assertNull($t->import($this->field, null, new FakeResolver(), $report));
    }

    /** The form Craft itself writes for links: a site, the url attribute and a fallback. */
    public function testReferenceTagWithFallbackAndAttributeIsPortable(): void
    {
        $entryRef = Ref::make('entry', 'e-uid', []);
        $resolver = new FakeResolver(elements: ['entry:123' => $entryRef]);
        $report = new Report();
        $t = new HtmlFieldTransformer();
        $out = $t->export($this->field, '<p>See {entry:123@1:url||https://prod.example.com/a}.</p>', $resolver, $report);
        $this->assertIsArray($out);
        $this->assertSame('<p>See {entry:__PORTER_ref1__@1:url||https://prod.example.com/a}.</p>', $out['markup']);
    }

    public function testBareReferenceTagWithNoAttributeIsPortable(): void
    {
        $entryRef = Ref::make('entry', 'e-uid', []);
        $resolver = new FakeResolver(elements: ['entry:123' => $entryRef]);
        $report = new Report();
        $t = new HtmlFieldTransformer();
        $out = $t->export($this->field, '<p>See {entry:123}.</p>', $resolver, $report);
        $this->assertIsArray($out);
        $this->assertSame('<p>See {entry:__PORTER_ref1__}.</p>', $out['markup']);
    }

    public function testBracesInProsePassThroughAsString(): void
    {
        $t = new HtmlFieldTransformer();
        $report = new Report();
        $markup = '<p>Use {siteName} or {{ twig }}</p>';
        $out = $t->export($this->field, $markup, new FakeResolver(), $report);
        $this->assertSame($markup, $out);
    }

    /** A fallback is allowed without an attribute, with or without a site, and with spaces. */
    public function testFallbackWithoutAttributeVariantsAreAllPortable(): void
    {
        $entryRef = Ref::make('entry', 'e-uid', []);
        $cases = [
            '<p>{entry:123||Fallback}</p>' => '<p>{entry:__PORTER_ref1__||Fallback}</p>',
            '<p>{entry:123@1||Fallback}</p>' => '<p>{entry:__PORTER_ref1__@1||Fallback}</p>',
            '<p>{entry:123 || Fallback}</p>' => '<p>{entry:__PORTER_ref1__ || Fallback}</p>',
        ];
        foreach ($cases as $input => $expected) {
            $resolver = new FakeResolver(elements: ['entry:123' => $entryRef]);
            $report = new Report();
            $t = new HtmlFieldTransformer();
            $out = $t->export($this->field, $input, $resolver, $report);
            $this->assertIsArray($out, "expected a portable array for: {$input}");
            $this->assertSame($expected, $out['markup']);
        }
    }

    public function testCaseInsensitiveRefHandleIsRecognizedAndCasePreserved(): void
    {
        $entryRef = Ref::make('entry', 'e-uid', []);
        $resolver = new FakeResolver(elements: ['entry:123' => $entryRef]);
        $report = new Report();
        $t = new HtmlFieldTransformer();
        $out = $t->export($this->field, '<p>See {Entry:123:url}.</p>', $resolver, $report);
        $this->assertIsArray($out);
        $this->assertSame('<p>See {Entry:__PORTER_ref1__:url}.</p>', $out['markup']);
    }

    public function testMalformedRefEntryIsSkippedWithWarningOnImport(): void
    {
        $portable = [
            '__portable' => 'htmlfield',
            'markup' => '<p>See {entry:__PORTER_ref1__:url} now.</p>',
            'refs' => ['ref1' => 'oops'],
        ];
        $report = new Report();
        $t = new HtmlFieldTransformer();
        $back = $t->import($this->field, $portable, new FakeResolver(), $report);
        $this->assertSame('<p>See  now.</p>', $back);
        $this->assertNotEmpty($report->warnings);
    }

    public function testNonArrayRefsIsGuardedWithWarningOnImport(): void
    {
        $portable = [
            '__portable' => 'htmlfield',
            'markup' => '<p>Hello</p>',
            'refs' => 'not-an-array',
        ];
        $report = new Report();
        $t = new HtmlFieldTransformer();
        $back = $t->import($this->field, $portable, new FakeResolver(), $report);
        $this->assertSame('<p>Hello</p>', $back);
        $this->assertNotEmpty($report->warnings);
    }

    // --- Malformed envelopes, against the real CraftResolver --------------------

    public function testArrayMarkupIsGuardedOnImportAgainstTheRealResolver(): void
    {
        $portable = ['__portable' => 'htmlfield', 'markup' => ['x'], 'refs' => []];
        $report = new Report();
        $t = new HtmlFieldTransformer();

        $back = $t->import($this->field, $portable, new CraftResolver(), $report);

        $this->assertSame('', $back, 'an unusable markup value must import as empty, not fatal');
        $this->assertNotEmpty($report->warnings);
        $this->assertStringContainsString('markup', $report->warnings[0]);
    }

    public function testNonStringScalarMarkupIsGuardedOnImport(): void
    {
        foreach ([5, 1.5, true] as $value) {
            $report = new Report();
            $t = new HtmlFieldTransformer();
            $back = $t->import($this->field, ['__portable' => 'htmlfield', 'markup' => $value, 'refs' => []], new CraftResolver(), $report);
            $this->assertSame('', $back, 'markup ' . var_export($value, true) . ' should not be coerced');
            $this->assertNotEmpty($report->warnings);
        }
    }

    public function testAbsentMarkupImportsAsEmptyWithoutAWarning(): void
    {
        $report = new Report();
        $t = new HtmlFieldTransformer();
        $back = $t->import($this->field, ['__portable' => 'htmlfield', 'refs' => []], new CraftResolver(), $report);
        $this->assertSame('', $back);
        $this->assertSame([], $report->warnings);
    }

    /**
     * Ref::isRef() checks only that the keys are present, so this reference reaches the
     * resolver, which rejects it. The warning that follows must not interpolate the array.
     */
    public function testRefPassingIsRefButFailingTheShapeGuardIsHandledOnTheCkeditorRoute(): void
    {
        $malformed = ['__portable' => 'ref', 'kind' => ['entry'], 'uid' => 'u'];
        $this->assertTrue(Ref::isRef($malformed), 'premise: Ref::isRef() lets this through');

        $portable = [
            '__portable' => 'htmlfield',
            'markup' => '<p>See {entry:__PORTER_ref1__:url} now.</p>',
            'refs' => ['ref1' => $malformed],
        ];
        $report = new Report();
        $t = new HtmlFieldTransformer();

        $back = $t->import($this->field, $portable, new CraftResolver(), $report);

        $this->assertSame('<p>See  now.</p>', $back, 'the unresolvable tag must be stripped');
        $this->assertSame([$malformed], $report->unresolved);
        $this->assertContains(
            "Field 'body': element reference (uid u) in CKEditor content could not be resolved in the target; reference tag removed.",
            $report->warnings,
        );
    }

    public function testArrayUidRefIsHandledOnTheCkeditorRoute(): void
    {
        $malformed = ['__portable' => 'ref', 'kind' => 'entry', 'uid' => ['x']];
        $portable = [
            '__portable' => 'htmlfield',
            'markup' => '<p>See {entry:__PORTER_ref1__:url} now.</p>',
            'refs' => ['ref1' => $malformed],
        ];
        $report = new Report();
        $t = new HtmlFieldTransformer();

        $back = $t->import($this->field, $portable, new CraftResolver(), $report);

        $this->assertSame('<p>See  now.</p>', $back);
        $this->assertSame([$malformed], $report->unresolved);
        $this->assertContains(
            "Field 'body': entry reference (uid ?) in CKEditor content could not be resolved in the target; reference tag removed.",
            $report->warnings,
        );
    }
}
