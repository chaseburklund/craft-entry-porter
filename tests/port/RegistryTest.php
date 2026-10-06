<?php

namespace chaseburklund\entryporter\tests\port;

use chaseburklund\entryporter\port\FieldDescriptor;
use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\port\ResolverInterface;
use chaseburklund\entryporter\port\TransformerInterface;
use chaseburklund\entryporter\tests\Support\FakeResolver;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    private function upperCaser(): TransformerInterface
    {
        return new class implements TransformerInterface {
            public function supports(string $fieldClass): bool { return $fieldClass === 'craft\\fields\\PlainText'; }
            public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed { return strtoupper((string)$value); }
            public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed { return strtolower((string)$value); }
        };
    }

    private function fallback(): TransformerInterface
    {
        return new class implements TransformerInterface {
            public function supports(string $fieldClass): bool { return true; }
            public function export(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed { $report->warn('fallback'); return $value; }
            public function import(FieldDescriptor $field, mixed $value, ResolverInterface $resolver, Report $report): mixed { return $value; }
        };
    }

    public function testRoutesToFirstSupportingTransformer(): void
    {
        $registry = new Registry($this->fallback());
        $registry->add($this->upperCaser());
        $report = new Report();
        $out = $registry->export(new FieldDescriptor('craft\\fields\\PlainText', 'heading'), 'hi', new FakeResolver(), $report);
        $this->assertSame('HI', $out);
        $this->assertSame([], $report->warnings);
    }

    public function testUnmatchedClassFallsBack(): void
    {
        $registry = new Registry($this->fallback());
        $registry->add($this->upperCaser());
        $report = new Report();
        $out = $registry->export(new FieldDescriptor('some\\Unknown', 'x'), ['a' => 1], new FakeResolver(), $report);
        $this->assertSame(['a' => 1], $out);
        $this->assertSame(['fallback'], $report->warnings);
    }

    public function testRefHelpersAndSkipSentinel(): void
    {
        $ref = Ref::make('asset', 'uid-1', ['volume' => 'assets'], ['url' => 'https://x/a.jpg']);
        $this->assertSame('ref', $ref['__portable']);
        $this->assertSame('asset', $ref['kind']);
        $this->assertSame('uid-1', $ref['uid']);
        $this->assertSame(['volume' => 'assets'], $ref['keys']);
        $this->assertSame('https://x/a.jpg', $ref['url']);
        $this->assertTrue(Ref::isRef($ref));
        $this->assertFalse(Ref::isRef(['kind' => 'asset']));
        $this->assertFalse(Ref::isRef('nope'));
        $this->assertTrue(Registry::isSkip(Registry::SKIP));
        $this->assertFalse(Registry::isSkip(['__portable' => 'ref']));
    }

    /**
     * A skip with a reason is still a skip, and a bare SKIP still works with no reason.
     */
    public function testSkipCarriesAReasonWithoutCeasingToBeASkip(): void
    {
        $unportable = Registry::skip(Registry::SKIP_UNPORTABLE);

        $this->assertTrue(Registry::isSkip($unportable), 'a reason must not make it stop being a skip');
        $this->assertSame('skip', $unportable['__portable']);
        $this->assertSame(Registry::SKIP_UNPORTABLE, Registry::skipReason($unportable));
        $this->assertSame(Registry::SKIP_DERIVED, Registry::skipReason(Registry::skip(Registry::SKIP_DERIVED)));

        $this->assertTrue(Registry::isSkip(Registry::SKIP));
        $this->assertNull(Registry::skipReason(Registry::SKIP), 'a bare skip records no reason, which is not an error');
    }

    /** Unrecognized reasons are ignored, so a payload cannot choose what the report says. */
    public function testAnUnrecognisedSkipReasonIsNotReported(): void
    {
        $this->assertNull(Registry::skipReason(['__portable' => 'skip', 'reason' => 'whatever']));
        $this->assertNull(Registry::skipReason(['__portable' => 'skip', 'reason' => ['unportable']]));
        $this->assertNull(Registry::skipReason(['__portable' => 'skip', 'reason' => null]));
        $this->assertNull(Registry::skipReason(['__portable' => 'ref', 'reason' => 'unportable']),
            'a non-skip value has no skip reason, whatever it claims');
        $this->assertNull(Registry::skipReason('unportable'));
        $this->assertNull(Registry::skipReason(null));
    }

    /**
     * Every accepted version is an integer, and the version this install writes is one it
     * accepts.
     */
    public function testThePayloadVersionConstantsStayCoherent(): void
    {
        $this->assertNotEmpty(Registry::SUPPORTED_PAYLOAD_VERSIONS);
        foreach (Registry::SUPPORTED_PAYLOAD_VERSIONS as $version) {
            $this->assertIsInt($version);
        }
        $this->assertIsInt(Registry::PAYLOAD_VERSION);
        $this->assertContains(Registry::PAYLOAD_VERSION, Registry::SUPPORTED_PAYLOAD_VERSIONS, 'an install must accept what it emits');
        $this->assertSame(max(Registry::SUPPORTED_PAYLOAD_VERSIONS), Registry::PAYLOAD_VERSION,
            'we never emit an older format than the newest one we understand');
    }

    public function testReportDedupesWarnings(): void
    {
        $report = new Report();
        $report->warn('same');
        $report->warn('same');
        $report->warn('other');
        $this->assertSame(['same', 'other'], $report->warnings);
        $arr = $report->toArray();
        $this->assertSame(['warnings', 'unresolved', 'resolvedByFallback'], array_keys($arr));
    }
}
