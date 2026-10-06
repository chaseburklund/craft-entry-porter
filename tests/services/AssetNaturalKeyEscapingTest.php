<?php

namespace chaseburklund\entryporter\tests\services;

use craft\helpers\Db;
use chaseburklund\entryporter\services\CraftResolver;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Tests that an asset's natural keys are matched literally.
 *
 * The filename and folder path come from the payload, and Craft's element queries read `,`
 * as OR and `*` as a wildcard. Unescaped, a filename of `*` would match the first asset in
 * the volume. Craft never allows those characters in filenames, so escaping rejects nothing
 * legitimate.
 *
 * The lookups that use these keys need a running Craft app, so the tests below check the
 * source of those methods for the escaping, and run the escaping itself directly.
 */
final class AssetNaturalKeyEscapingTest extends TestCase
{
    /** Escaped values are compared literally, so they match no real file. */
    public function testEscapeParamNeutralisesBothQueryOperators(): void
    {
        $this->assertSame('a\,b.jpg', Db::escapeParam('a,b.jpg'), 'a comma is Craft\'s OR');
        $this->assertSame('\*', Db::escapeParam('*'), 'an asterisk is Craft\'s wildcard');
        $this->assertSame('logo\*.png', Db::escapeParam('logo*.png'));
        $this->assertSame('uploads/', Db::escapeParam('uploads/'), 'a real folder path is untouched');
        $this->assertSame('photo.jpg', Db::escapeParam('photo.jpg'), 'a real filename is untouched');
    }

    public function testTheAssetNaturalKeyLookupEscapesBothPayloadControlledStrings(): void
    {
        $source = self::sourceOf('findAssetByNaturalKey');

        $this->assertStringContainsString('filename(Db::escapeParam($filename))', $source,
            'the payload-controlled filename must not reach AssetQuery::filename() raw — Craft reads `,` as OR and `*` as a wildcard there (Db::parseParam via AssetQuery)');
        $this->assertStringContainsString('folderPath(Db::escapeParam($folderPath))', $source,
            'the payload-controlled folderPath must not reach AssetQuery::folderPath() raw, for the same reason');
        $this->assertStringNotContainsString('filename($filename)', $source);
        $this->assertStringNotContainsString('folderPath($folderPath)', $source);
    }

    /**
     * The folder check decides whether `createFolders` permission is required, so an
     * unescaped `*` here would skip that permission check.
     */
    public function testTheFolderExistenceCheckEscapesItsPathToo(): void
    {
        $source = self::sourceOf('folderExistsInVolume');

        $this->assertStringContainsString("Db::escapeParam(\$trimmed.'/')", $source,
            'an unescaped `*` here makes the createFolders permission check pass vacuously');
        $this->assertStringNotContainsString("'path'=>\$trimmed.'/'", $source);
    }

    /** Returns a method's source with comments removed and whitespace collapsed. */
    private static function sourceOf(string $method): string
    {
        $reflected = new ReflectionMethod(CraftResolver::class, $method);
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

        return (string)preg_replace('/\s+/', '', $code);
    }
}
