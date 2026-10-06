<?php

namespace chaseburklund\entryporter\tests\services;

use chaseburklund\entryporter\port\Registry;
use chaseburklund\entryporter\services\Importer;
use PHPUnit\Framework\TestCase;

/**
 * Tests Importer::validatePayload(), the part of the importer that does not need a Craft app.
 *
 * Cases cover each way a value can be missing or wrong: absent, present but null, present but
 * empty, the wrong type, and optional keys that may legitimately be null.
 */
final class PayloadValidatorTest extends TestCase
{
    private function validPayload(): array
    {
        return [
            'porter' => ['version' => Registry::PAYLOAD_VERSION, 'source' => ['origin' => 'https://x', 'craft' => '5.9.0', 'configTimestamp' => 1, 'fieldLayoutUid' => 'u']],
            'entry' => ['uid' => 'e-uid', 'sectionHandle' => 'pages', 'typeHandle' => 'page', 'siteHandle' => 'default',
                        'title' => 'T', 'slug' => 't', 'enabled' => true, 'postDate' => null, 'parent' => null, 'fields' => []],
        ];
    }

    /** A null postDate and parent are normal and must not be reported. */
    public function testValidPayloadHasNoErrors(): void
    {
        $this->assertSame([], Importer::validatePayload($this->validPayload()));
    }

    public function testWrongVersionAndMissingKeysAreReported(): void
    {
        $bad = $this->validPayload();
        $bad['porter']['version'] = 99;
        unset($bad['entry']['sectionHandle'], $bad['entry']['fields']);
        $errors = Importer::validatePayload($bad);
        $this->assertCount(3, $errors);
        $this->assertStringContainsString('version', implode(' ', $errors));
        $this->assertStringContainsString('sectionHandle', implode(' ', $errors));
        $this->assertStringContainsString('fields', implode(' ', $errors));
    }

    public function testNonArrayEntryIsReported(): void
    {
        $errors = Importer::validatePayload(['porter' => ['version' => Registry::PAYLOAD_VERSION]]);
        $this->assertNotEmpty($errors);
    }

    public function testScalarEntryIsReportedAndDoesNotFatal(): void
    {
        $errors = Importer::validatePayload(['porter' => ['version' => Registry::PAYLOAD_VERSION], 'entry' => 'nope']);
        $this->assertSame(["Missing 'entry' object."], $errors);
    }

    public function testScalarPorterIsReportedAndDoesNotFatal(): void
    {
        $payload = $this->validPayload();
        $payload['porter'] = 'nope';
        $errors = Importer::validatePayload($payload);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('version', $errors[0]);
    }

    public function testMissingPorterKeyIsReportedAsVersionFailure(): void
    {
        $payload = $this->validPayload();
        unset($payload['porter']);
        $errors = Importer::validatePayload($payload);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('version', $errors[0]);
    }

    public function testAllFiveRequiredEntryKeysAreNamedIndividually(): void
    {
        $payload = $this->validPayload();
        foreach (['uid', 'sectionHandle', 'typeHandle', 'siteHandle', 'fields'] as $key) {
            unset($payload['entry'][$key]);
        }
        $errors = Importer::validatePayload($payload);
        $this->assertCount(5, $errors);
        foreach (['uid', 'sectionHandle', 'typeHandle', 'siteHandle', 'fields'] as $key) {
            $this->assertContains("Missing 'entry.$key'.", $errors);
        }
    }

    /**
     * When the source entry's type has been deleted, the exporter writes a null typeHandle.
     * The key is present, so this is refused only because the check treats null as missing.
     */
    public function testDegradedExportWithNullTypeHandleIsRefusedUpFront(): void
    {
        $payload = $this->validPayload();
        $payload['porter']['source']['fieldLayoutUid'] = null;
        $payload['entry']['typeHandle'] = null;
        $payload['entry']['fields'] = [];

        $errors = Importer::validatePayload($payload);
        $this->assertCount(1, $errors);
        $this->assertSame("Missing 'entry.typeHandle'.", $errors[0]);
    }

    public function testAnyRequiredKeyPresentButNullIsRefused(): void
    {
        foreach (['uid', 'sectionHandle', 'typeHandle', 'siteHandle', 'fields'] as $key) {
            $payload = $this->validPayload();
            $payload['entry'][$key] = null;
            $this->assertSame(
                ["Missing 'entry.$key'."],
                Importer::validatePayload($payload),
                "present-but-null '$key' should be refused",
            );
        }
    }

    /**
     * Empty handles pass this check. They fail later, with a message naming the missing
     * section or entry type.
     */
    public function testEmptyStringHandlesPassStructuralValidation(): void
    {
        $payload = $this->validPayload();
        $payload['entry']['sectionHandle'] = '';
        $payload['entry']['typeHandle'] = '';
        $this->assertSame([], Importer::validatePayload($payload));
    }

    public function testEmptyFieldsArrayIsValid(): void
    {
        $payload = $this->validPayload();
        $payload['entry']['fields'] = [];
        $this->assertSame([], Importer::validatePayload($payload));
    }

    public function testScalarFieldsIsReported(): void
    {
        $payload = $this->validPayload();
        $payload['entry']['fields'] = 'not-an-object';
        $errors = Importer::validatePayload($payload);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString("entry.fields", $errors[0]);
    }

    /** Handles are passed to Craft methods that require strings. */
    public function testNonStringHandlesAreReported(): void
    {
        foreach (['uid' => 123, 'sectionHandle' => ['a'], 'typeHandle' => ['nested' => 1], 'siteHandle' => 4.5] as $key => $value) {
            $payload = $this->validPayload();
            $payload['entry'][$key] = $value;
            $errors = Importer::validatePayload($payload);
            $this->assertSame(["'entry.$key' must be a string."], $errors, "non-string '$key' should be refused");
        }
    }

    public function testFutureVersionIsRefused(): void
    {
        $payload = $this->validPayload();
        $payload['porter']['version'] = max(Registry::SUPPORTED_PAYLOAD_VERSIONS) + 1;
        $errors = Importer::validatePayload($payload);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString((string)Registry::PAYLOAD_VERSION, $errors[0]);
    }

    // --- Payload versions -----------------------------------------------------

    /**
     * Version 1 installs would corrupt a version 2 rich-text value, so this install must
     * write a version they refuse.
     */
    public function testTheEmittedVersionIsOneAnOlderTargetRefuses(): void
    {
        $this->assertSame(2, Registry::PAYLOAD_VERSION,
            'Exporter writes this; it must not be a version a pre-htmlfield install would accept');
    }

    public function testAV1PayloadFromAnOlderSourceIsStillAccepted(): void
    {
        $payload = $this->validPayload();
        $payload['porter']['version'] = 1;
        $this->assertSame([], Importer::validatePayload($payload));
    }

    public function testAV2PayloadIsAccepted(): void
    {
        $payload = $this->validPayload();
        $payload['porter']['version'] = 2;
        $this->assertSame([], Importer::validatePayload($payload));
    }

    /** The refusal lists every accepted version, so the user knows whether to re-export or upgrade. */
    public function testAnUnsupportedVersionIsRefusedAndTheMessageNamesTheWholeAcceptedSet(): void
    {
        foreach ([3, 0, -1, 99, PHP_INT_MAX] as $version) {
            $payload = $this->validPayload();
            $payload['porter']['version'] = $version;
            $errors = Importer::validatePayload($payload);
            $this->assertCount(1, $errors, "version $version should be refused");
            foreach (Registry::SUPPORTED_PAYLOAD_VERSIONS as $accepted) {
                $this->assertStringContainsString((string)$accepted, $errors[0],
                    "the refusal for version $version should name accepted version $accepted");
            }
        }
    }

    public function testTheEmittedVersionIsAlwaysAccepted(): void
    {
        $this->assertContains(Registry::PAYLOAD_VERSION, Registry::SUPPORTED_PAYLOAD_VERSIONS);
        $payload = $this->validPayload();
        $payload['porter']['version'] = Registry::PAYLOAD_VERSION;
        $this->assertSame([], Importer::validatePayload($payload));
    }

    /** The version must be an integer; strings, floats and booleans are not cast. */
    public function testVersionMustBeAnIntegerNotACastableLookalike(): void
    {
        foreach ([1.9, '1', true, 1.0, '2', 2.0, 2.5, '2.0'] as $version) {
            $payload = $this->validPayload();
            $payload['porter']['version'] = $version;
            $errors = Importer::validatePayload($payload);
            $this->assertCount(1, $errors, 'version ' . var_export($version, true) . ' should be refused');
            $this->assertStringContainsString('version', $errors[0]);
        }
    }

    public function testOptionalTitleAndSlugMayBeAbsent(): void
    {
        $payload = $this->validPayload();
        unset($payload['entry']['title'], $payload['entry']['slug'], $payload['entry']['enabled']);
        $this->assertSame([], Importer::validatePayload($payload));
    }

    /** Title and slug are assigned to nullable string properties. */
    public function testNonStringTitleOrSlugIsReported(): void
    {
        foreach (['title', 'slug'] as $key) {
            foreach ([['an', 'array'], 42, 3.5, ['nested' => ['deep']]] as $value) {
                $payload = $this->validPayload();
                $payload['entry'][$key] = $value;
                $this->assertSame(
                    ["'entry.$key' must be a string."],
                    Importer::validatePayload($payload),
                    "non-string '$key' (" . gettype($value) . ') should be refused',
                );
            }
        }
    }

    /** `enabled` controls publishing, so a value such as "no" must not be cast to true. */
    public function testNonBooleanEnabledIsReported(): void
    {
        foreach (['no', 'false', 0, 1, [], 'true'] as $value) {
            $payload = $this->validPayload();
            $payload['entry']['enabled'] = $value;
            $this->assertSame(
                ["'entry.enabled' must be true or false."],
                Importer::validatePayload($payload),
                'enabled ' . var_export($value, true) . ' should be refused',
            );
        }
    }

    public function testBooleanEnabledIsAccepted(): void
    {
        foreach ([true, false] as $value) {
            $payload = $this->validPayload();
            $payload['entry']['enabled'] = $value;
            $this->assertSame([], Importer::validatePayload($payload));
        }
    }

    /** Each of these array shapes makes Craft's date parsing throw a TypeError. */
    public function testNestedArrayPostDateShapesAreReported(): void
    {
        $shapes = [
            'datetime' => ['datetime' => ['x']],
            'date' => ['date' => ['x']],
            'time' => ['date' => '2020-01-01', 'time' => ['x']],
        ];
        foreach ($shapes as $label => $value) {
            $payload = $this->validPayload();
            $payload['entry']['postDate'] = $value;
            $this->assertSame(
                ["'entry.postDate' must be a string."],
                Importer::validatePayload($payload),
                "nested-array postDate via '$label' should be refused",
            );
        }
    }

    /** A numeric postDate would be parsed as some arbitrary date rather than refused. */
    public function testNonStringScalarPostDateIsReported(): void
    {
        foreach ([5, 1.5, true, 1735689600] as $value) {
            $payload = $this->validPayload();
            $payload['entry']['postDate'] = $value;
            $this->assertSame(
                ["'entry.postDate' must be a string."],
                Importer::validatePayload($payload),
                'postDate ' . var_export($value, true) . ' should be refused',
            );
        }
    }

    public function testAtomStringPostDateIsAccepted(): void
    {
        $payload = $this->validPayload();
        $payload['entry']['postDate'] = '2026-08-29T15:27:50+00:00';
        $this->assertSame([], Importer::validatePayload($payload));
    }

    public function testNonStringParentKindIsReported(): void
    {
        foreach ([['entry'], ['a' => 'b'], 7, false] as $value) {
            $payload = $this->validPayload();
            $payload['entry']['parent'] = ['kind' => $value, 'uid' => 'p-uid'];
            $this->assertSame(
                ["'entry.parent.kind' must be a string."],
                Importer::validatePayload($payload),
                'parent.kind ' . var_export($value, true) . ' should be refused',
            );
        }
    }

    /** A scalar parent is reported once, not also as a bad kind. */
    public function testScalarParentIsReportedOnce(): void
    {
        $payload = $this->validPayload();
        $payload['entry']['parent'] = 'not-a-ref';
        $this->assertSame(["'entry.parent' must be an object."], Importer::validatePayload($payload));
    }

    public function testMalformedPorterSourceMembersAreReported(): void
    {
        $cases = [
            ['fieldLayoutUid', ['x'], "'porter.source.fieldLayoutUid' must be a string."],
            ['fieldLayoutUid', 42, "'porter.source.fieldLayoutUid' must be a string."],
            ['origin', ['https://x'], "'porter.source.origin' must be a string."],
            ['configTimestamp', 'not-a-number', "'porter.source.configTimestamp' must be an integer."],
            ['configTimestamp', ['1'], "'porter.source.configTimestamp' must be an integer."],
        ];
        foreach ($cases as [$key, $value, $expected]) {
            $payload = $this->validPayload();
            $payload['porter']['source'][$key] = $value;
            $this->assertSame([$expected], Importer::validatePayload($payload), "porter.source.$key");
        }
    }

    public function testScalarPorterSourceIsReported(): void
    {
        $payload = $this->validPayload();
        $payload['porter']['source'] = 'nope';
        $this->assertSame(["'porter.source' must be an object."], Importer::validatePayload($payload));
    }

    public function testAbsentPorterSourceIsAccepted(): void
    {
        $payload = $this->validPayload();
        unset($payload['porter']['source']);
        $this->assertSame([], Importer::validatePayload($payload));
    }

    public function testWellFormedAndAbsentParentAreAccepted(): void
    {
        $payload = $this->validPayload();
        $payload['entry']['parent'] = ['kind' => 'entry', 'uid' => 'p-uid', 'keys' => ['section' => 'pages', 'type' => 'page', 'slug' => 'p']];
        $this->assertSame([], Importer::validatePayload($payload));

        $payload['entry']['parent'] = null;
        $this->assertSame([], Importer::validatePayload($payload));
    }

    public function testVersionAndEntryFailuresAreReportedTogether(): void
    {
        $errors = Importer::validatePayload([]);
        $this->assertCount(2, $errors);
        $this->assertStringContainsString('version', $errors[0]);
        $this->assertSame("Missing 'entry' object.", $errors[1]);
    }
}
