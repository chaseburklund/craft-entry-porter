<?php

namespace chaseburklund\entryporter\tests\port;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\RegistryFactory;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\services\CraftResolver;
use chaseburklund\entryporter\tests\Support\FakeResolver;
use chaseburklund\entryporter\transformers\RelationTransformer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class RegistryFactoryTest extends TestCase
{
    public function testBuildRoutesFieldClassesCorrectly(): void
    {
        $registry = RegistryFactory::build();
        $report = new Report();
        $resolver = new FakeResolver();

        // Known-safe passes silently
        $this->assertSame('x', $registry->export(new FieldDescriptor('craft\\fields\\PlainText', 'a'), 'x', $resolver, $report));
        $this->assertSame([], $report->warnings);

        // OptimizedImages is skipped
        $this->assertTrue(Registry::isSkip($registry->export(new FieldDescriptor('nystudio107\\imageoptimize\\fields\\OptimizedImages', 'b'), ['v'], $resolver, $report)));

        // Relations transform to ref arrays (empty here — no elements in FakeResolver)
        $this->assertSame([], $registry->export(new FieldDescriptor('craft\\fields\\Assets', 'c'), [1], $resolver, $report));

        // Unknown types warn via fallback
        $before = count($report->warnings);
        $registry->export(new FieldDescriptor('vendor\\odd\\Field', 'd'), 1, $resolver, $report);
        $this->assertGreaterThan($before, count($report->warnings));
    }

    /**
     * Routes one field class to each registered transformer and checks for behavior specific
     * to that transformer, so that a missing registration (which would fall through to
     * FallbackTransformer) fails.
     */
    public function testEveryRegisteredTransformerFamilyIsReachable(): void
    {
        $registry = RegistryFactory::build();
        $resolver = new FakeResolver();

        // SkipTransformer: skipped with the `derived` reason and no warning.
        $report = new Report();
        $out = $registry->export(new FieldDescriptor('nystudio107\\imageoptimize\\fields\\OptimizedImages', 'f'), ['anything'], $resolver, $report);
        $this->assertTrue(Registry::isSkip($out));
        $this->assertSame(Registry::SKIP_DERIVED, Registry::skipReason($out));
        $this->assertSame([], $report->warnings);

        // SeoSettingsTransformer: passed through with an SEOmatic-specific warning.
        $report = new Report();
        $seoValue = ['title' => 'x'];
        $out = $registry->export(new FieldDescriptor('nystudio107\\seomatic\\fields\\SeoSettings', 'f'), $seoValue, $resolver, $report);
        $this->assertSame($seoValue, $out);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('SEOmatic', $report->warnings[0]);

        // RelationTransformer: an unresolvable ID is dropped.
        $report = new Report();
        $out = $registry->export(new FieldDescriptor('craft\\fields\\Assets', 'f'), [42], $resolver, $report);
        $this->assertSame([], $out);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('could not describe', $report->warnings[0]);

        // MatrixTransformer: blocks are re-keyed, and nested fields are routed through the
        // same registry, so the nested relation field drops its unresolvable ID.
        $report = new Report();
        $resolverWithEntryType = new FakeResolver(entryTypeFields: [
            't' => ['inner' => new FieldDescriptor('craft\\fields\\Assets', 'inner')],
        ]);
        $out = $registry->export(
            new FieldDescriptor('craft\\fields\\Matrix', 'f'),
            [['type' => 't', 'fields' => ['inner' => [42]]]],
            $resolverWithEntryType,
            $report,
        );
        $this->assertArrayHasKey('new1', $out);
        $this->assertSame('t', $out['new1']['type']);
        $this->assertSame([], $out['new1']['fields']['inner']);

        // NeoTransformer: likewise.
        $report = new Report();
        $resolverWithBlockType = new FakeResolver(neoBlockFields: [
            'f:t' => ['inner' => new FieldDescriptor('craft\\fields\\Assets', 'inner')],
        ]);
        $out = $registry->export(
            new FieldDescriptor('benf\\neo\\Field', 'f'),
            [['type' => 't', 'fields' => ['inner' => [42]]]],
            $resolverWithBlockType,
            $report,
        );
        $this->assertArrayHasKey('new1', $out);
        $this->assertSame([], $out['new1']['fields']['inner']);

        // HyperTransformer: identified by its own warning text.
        $report = new Report();
        $out = $registry->export(new FieldDescriptor('verbb\\hyper\\fields\\HyperField', 'f'), ['not-an-array'], $resolver, $report);
        $this->assertSame(['not-an-array'], $out);
        $this->assertCount(1, $report->warnings);
        $this->assertStringContainsString('Hyper link', $report->warnings[0]);

        // HtmlFieldTransformer (CKEditor): an undescribable reference tag is removed.
        $report = new Report();
        $out = $registry->export(new FieldDescriptor('craft\\ckeditor\\Field', 'f'), '<p>{entry:999}</p>', $resolver, $report);
        $this->assertIsArray($out);
        $this->assertStringNotContainsString('{entry:999}', $out['markup'] ?? '');
        $this->assertNotSame([], $report->warnings);

        // KnownSafeTransformer: passed through without a warning.
        $report = new Report();
        $out = $registry->export(new FieldDescriptor('craft\\fields\\PlainText', 'f'), 'hello', $resolver, $report);
        $this->assertSame('hello', $out);
        $this->assertSame([], $report->warnings);

        // LinkTransformer: the element ID becomes a placeholder and a reference.
        $report = new Report();
        $entryRef = Ref::make('entry', 'e-uid', []);
        $out = $registry->export(
            new FieldDescriptor('craft\\fields\\Link', 'f'),
            ['value' => '{entry:123@1:url}', 'type' => 'entry'],
            new FakeResolver(elements: ['entry:123' => $entryRef]),
            $report,
        );
        $this->assertSame('{entry:__PORTER_ID__@1:url}', $out['value']);
        $this->assertSame($entryRef, $out['ref']);
        $this->assertSame([], $report->warnings);

        // LenzLinkTransformer: the element ID in the JSON string becomes a reference.
        $report = new Report();
        $lenzRef = Ref::make('entry', 'lenz-uid', []);
        $out = $registry->export(
            new FieldDescriptor('lenz\\linkfield\\fields\\LinkField', 'f'),
            '{"linkedId":67,"linkedSiteId":null,"linkedTitle":null,"linkedUrl":null,"payload":null,"type":"entry"}',
            new FakeResolver(elements: ['entry:67' => $lenzRef]),
            $report,
        );
        $this->assertSame('lenzlink', $out['__portable'] ?? null);
        $this->assertSame($lenzRef, $out['ref']);
        $this->assertNull($out['link']['linkedId']);
        $this->assertSame([], $report->warnings);

        // HtmlFieldTransformer (Redactor).
        $report = new Report();
        $out = $registry->export(new FieldDescriptor('craft\\redactor\\Field', 'f'), '<p>{entry:999}</p>', $resolver, $report);
        $this->assertIsArray($out);
        $this->assertStringNotContainsString('999', $out['markup'] ?? '');
        $this->assertStringContainsString('Redactor', $report->warnings[0]);

        // RelationTransformer (Tags).
        $report = new Report();
        $out = $registry->export(new FieldDescriptor('craft\\fields\\Tags', 'f'), [42], $resolver, $report);
        $this->assertSame([], $out, 'an undescribable tag id must be dropped, never copied through');
        $this->assertStringContainsString('could not describe tag #42', $report->warnings[0]);

        // SimpleMapTransformer: the record IDs are dropped, without a warning.
        $report = new Report();
        $out = $registry->export(
            new FieldDescriptor('ether\\simplemap\\fields\\MapField', 'f'),
            ['id' => 3, 'ownerId' => 9, 'lat' => 1.5, 'lng' => 2.5],
            $resolver,
            $report,
        );
        $this->assertSame(['lat' => 1.5, 'lng' => 2.5], $out);
        $this->assertSame([], $report->warnings);
    }

    /**
     * Every kind RelationTransformer uses must be known to CraftResolver; otherwise every
     * relation in that field would be dropped.
     */
    public function testEveryRelationKindIsKnownToTheResolver(): void
    {
        $kinds = (new \ReflectionClass(RelationTransformer::class))->getConstant('KINDS');
        $elementClasses = (new \ReflectionClass(CraftResolver::class))->getConstant('KIND_ELEMENT_CLASSES');

        $this->assertNotSame([], $kinds);
        foreach ($kinds as $fieldClass => $kind) {
            $this->assertArrayHasKey(
                $kind,
                $elementClasses,
                "RelationTransformer maps {$fieldClass} to kind '{$kind}', which CraftResolver::KIND_ELEMENT_CLASSES does not know — describeElement() and resolveByNaturalKeys() need matching arms or every relation in that field is silently dropped.",
            );
        }
    }

    /**
     * UnportableTransformer must be registered ahead of every transformer that could claim
     * the same field classes, since the first matching transformer wins. Its class list is
     * empty at present, so this reads the registration order from RegistryFactory's source.
     * SkipTransformer may come first because its class list cannot overlap.
     */
    public function testUnportableTransformerStaysRegisteredAheadOfEverythingButTheProvenDisjointSkip(): void
    {
        $order = self::transformerRegistrationOrder();

        $this->assertContains(
            'Unportable',
            $order,
            'UnportableTransformer must stay registered in RegistryFactory::build() -- delete the '
                . 'add() call and a field type this project has established carries source element '
                . 'ids falls through to FallbackTransformer, which copies the id verbatim onto '
                . 'unrelated target content.',
        );

        $registeredBefore = array_slice($order, 0, array_search('Unportable', $order, true));
        $notProvenDisjoint = array_values(array_diff($registeredBefore, ['Skip']));

        $this->assertSame(
            [],
            $notProvenDisjoint,
            'UnportableTransformer must be registered ahead of every transformer except '
                . 'SkipTransformer (proven disjoint by a fixed class list): found '
                . implode(', ', array_map(static fn (string $name): string => $name . 'Transformer', $notProvenDisjoint))
                . ' registered ahead of it instead, which could silently claim a class the day '
                . 'UnportableTransformer\'s list is re-armed.',
        );
    }

    /**
     * The transformer names (without the "Transformer" suffix) in the order
     * RegistryFactory::build() registers them, read from its source with comments removed.
     */
    private static function transformerRegistrationOrder(): array
    {
        $reflected = new ReflectionMethod(RegistryFactory::class, 'build');
        $lines = file($reflected->getFileName());
        $body = implode('', array_slice(
            $lines,
            $reflected->getStartLine() - 1,
            $reflected->getEndLine() - $reflected->getStartLine() + 1,
        ));

        $code = '';
        foreach (token_get_all('<?php ' . $body) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        preg_match_all('/\$registry\s*->\s*add\s*\(\s*new\s+(\w+?)Transformer\s*\(/', $code, $matches);
        return $matches[1];
    }
}
