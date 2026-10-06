<?php

namespace chaseburklund\entryporter\tests\services;

use chaseburklund\entryporter\services\ImportException;
use chaseburklund\entryporter\services\Importer;
use PHPUnit\Framework\TestCase;

/**
 * Tests Importer::convertingFieldValueFailures(), which wraps each save call.
 *
 * A field type can reject a payload value while Craft normalizes it during the save, and the
 * set of field types is open-ended, so these failures cannot all be prevented in advance.
 * The wrapper turns the exceptions such a rejection produces (TypeError, ErrorException and
 * Yii's InvalidArgumentException) into an ImportException, and lets everything else through
 * unchanged, so that bugs are not reported as bad content.
 *
 * Normalizing a value needs a running Craft app, so each case reproduces the failing call
 * with the same signature and payload shape.
 */
final class ImporterFieldValueNetTest extends TestCase
{
    /** The handles a realistic import would have set values for. */
    private const HANDLES = ['body', 'eventDate', 'links'];

    /**
     * A Date or Time field given an array where Craft's date parsing expects a string.
     */
    private function dateFieldSink(): callable
    {
        $parseDate = function (string $value): string {
            return $value;
        };
        // The payload shape: {"fields":{"eventDate":{"date":["x"]}}}
        $payloadValue = ['date' => ['x']];
        return static fn() => $parseDate($payloadValue['date']);
    }

    /**
     * A Hyper link attribute given an array; Hyper assigns attributes directly to typed
     * properties such as `?string $linkText`.
     */
    private function hyperLinkSink(): callable
    {
        $link = new class {
            public ?string $linkText = null;
        };
        $payloadValue = ['linkText' => ['x']];
        return static function () use ($link, $payloadValue) {
            $link->linkText = $payloadValue['linkText'];
            return $link;
        };
    }

    /**
     * A Link field whose link type is registered on the source (by a plugin) but not on the
     * target. Craft throws yii\base\InvalidArgumentException("Invalid link type: ...").
     */
    private function unregisteredLinkTypeSink(): callable
    {
        // The link types registered on the target.
        $registeredTypes = ['url' => 'craft\\fields\\linktypes\\Url', 'entry' => 'craft\\fields\\linktypes\\Entry'];
        // The payload shape: {"fields":{"cta":{"type":"acme-doi","value":"10.1000/182"}}}
        $payloadValue = ['type' => 'acme-doi', 'value' => '10.1000/182'];
        return static function () use ($registeredTypes, $payloadValue) {
            $typeId = $payloadValue['type'];
            if (!isset($registeredTypes[$typeId])) {
                throw new \yii\base\InvalidArgumentException("Invalid link type: $typeId");
            }
            return $typeId;
        };
    }

    // --- Converted failures --------------------------------------------------------

    public function testDateFieldValueOfTheWrongShapeBecomesAnImportException(): void
    {
        try {
            Importer::convertingFieldValueFailures(self::HANDLES, $this->dateFieldSink());
            $this->fail('expected an ImportException');
        } catch (ImportException $e) {
            $this->assertInstanceOf(\TypeError::class, $e->getPrevious(), 'the original must survive for the log');
            $this->assertStringContainsString('could not accept', $e->getMessage());
            // Assets created earlier in the import are saved outside the transaction, so the
            // message only claims that no entry or draft was written.
            $this->assertStringContainsString('no entry or draft was written', $e->getMessage());
        }
    }

    public function testHyperLinkAttributeOfTheWrongShapeBecomesAnImportException(): void
    {
        try {
            Importer::convertingFieldValueFailures(self::HANDLES, $this->hyperLinkSink());
            $this->fail('expected an ImportException');
        } catch (ImportException $e) {
            $this->assertInstanceOf(\TypeError::class, $e->getPrevious());
            $this->assertStringContainsString('could not accept', $e->getMessage());
        }
    }

    public function testUnregisteredLinkTypeBecomesAnImportException(): void
    {
        try {
            Importer::convertingFieldValueFailures(self::HANDLES, $this->unregisteredLinkTypeSink());
            $this->fail('expected an ImportException');
        } catch (ImportException $e) {
            $this->assertInstanceOf(\yii\base\InvalidArgumentException::class, $e->getPrevious(), 'the original must survive for the log');
            $this->assertStringContainsString('could not accept', $e->getMessage());
            $this->assertStringContainsString('no entry or draft was written', $e->getMessage());
            // The original message is what tells the user what differs between the installs.
            $this->assertStringContainsString('Invalid link type: acme-doi', $e->getMessage());
        }
    }

    /** Yii turns PHP warnings into ErrorExceptions; the error handler here does the same. */
    public function testArrayToStringConversionIsAlsoConverted(): void
    {
        $previous = set_error_handler(static function (int $no, string $str): bool {
            throw new \ErrorException($str);
        });
        try {
            Importer::convertingFieldValueFailures(self::HANDLES, static fn() => (string)(['x']));
            $this->fail('expected an ImportException');
        } catch (ImportException $e) {
            $this->assertInstanceOf(\ErrorException::class, $e->getPrevious());
            $this->assertStringContainsString('Array to string conversion', $e->getMessage());
        } finally {
            set_error_handler($previous);
        }
    }

    // --- The message -----------------------------------------------------------------

    /**
     * The failing field cannot be identified, so the message lists the fields this import set
     * and includes the original error.
     */
    public function testMessageNamesTheFieldsThisImportSetValuesForAndCarriesTheOriginalText(): void
    {
        try {
            Importer::convertingFieldValueFailures(self::HANDLES, $this->dateFieldSink());
            $this->fail('expected an ImportException');
        } catch (ImportException $e) {
            foreach (self::HANDLES as $handle) {
                $this->assertStringContainsString($handle, $e->getMessage(), "the message should name '$handle'");
            }
            $this->assertStringContainsString('TypeError', $e->getMessage(), 'the operator needs the underlying error, not just a category');
            $this->assertStringContainsString('must be of type string, array given', $e->getMessage());
        }
    }

    public function testMessageWithNoFieldHandlesDoesNotClaimAFieldList(): void
    {
        try {
            Importer::convertingFieldValueFailures([], $this->dateFieldSink());
            $this->fail('expected an ImportException');
        } catch (ImportException $e) {
            $this->assertStringContainsString('no field values', $e->getMessage());
        }
    }

    // --- Not converted ---------------------------------------------------------------

    /** Other errors, such as calling a method on null, indicate bugs and pass through. */
    public function testAPlainErrorIsNotCaught(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Call to a member function handle() on null');
        Importer::convertingFieldValueFailures(self::HANDLES, static function () {
            /** @var object|null $nothing */
            $nothing = null;
            return $nothing->handle();
        });
    }

    /**
     * Yii's InvalidArgumentException and PHP's are unrelated classes, so catching Yii's does
     * not catch PHP's, or Yii's own parent classes.
     */
    public function testTheSplSiblingAndTheYiiAncestorsAreNotCaught(): void
    {
        $thrown = [
            new \InvalidArgumentException('SPL: sibling, not parent'),
            new \BadMethodCallException('the yii one\'s direct ancestor'),
            new \BadFunctionCallException('one further up the same chain'),
            new \yii\base\InvalidConfigException('a yii exception on a different branch'),
        ];
        foreach ($thrown as $t) {
            try {
                Importer::convertingFieldValueFailures(self::HANDLES, static fn() => throw $t);
                $this->fail('expected the original throwable');
            } catch (\Throwable $e) {
                $this->assertSame($t, $e, get_class($t) . ' must pass through untouched');
            }
        }
    }

    /** Database and other failures must not be reported as a content problem. */
    public function testOrdinaryExceptionsPassThroughUnchanged(): void
    {
        foreach ([new \RuntimeException('db is down'), new \LogicException('nope')] as $thrown) {
            try {
                Importer::convertingFieldValueFailures(self::HANDLES, static fn() => throw $thrown);
                $this->fail('expected the original throwable');
            } catch (\Throwable $e) {
                $this->assertSame($thrown, $e, get_class($thrown) . ' must pass through untouched');
            }
        }
    }

    /** An ImportException thrown by the save itself keeps its own, more specific message. */
    public function testAnImportExceptionFromInsideIsNotRewrapped(): void
    {
        $original = new ImportException('Draft save failed: Title cannot be blank.');
        try {
            Importer::convertingFieldValueFailures(self::HANDLES, static fn() => throw $original);
            $this->fail('expected the original ImportException');
        } catch (ImportException $e) {
            $this->assertSame($original, $e);
            $this->assertNull($e->getPrevious());
        }
    }

    // --- Success -----------------------------------------------------------------------

    public function testSuccessfulSaveIsReturnedUnchanged(): void
    {
        $this->assertTrue(Importer::convertingFieldValueFailures(self::HANDLES, static fn() => true));
        $this->assertFalse(Importer::convertingFieldValueFailures(self::HANDLES, static fn() => false));
        $sentinel = new \stdClass();
        $this->assertSame($sentinel, Importer::convertingFieldValueFailures([], static fn() => $sentinel));
    }
}
