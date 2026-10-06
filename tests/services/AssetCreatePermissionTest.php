<?php

namespace chaseburklund\entryporter\tests\services;

use chaseburklund\entryporter\port\Ref;
use chaseburklund\entryporter\port\Report;
use chaseburklund\entryporter\services\CraftResolver;
use chaseburklund\entryporter\tests\Support\RecordingResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests the volume permission check on asset creation.
 *
 * An asset is created only if the importing user may save assets in the volume, and may
 * create folders there when the target folder does not exist yet, matching Craft's own asset
 * controller. Entry permissions do not cover volumes, so this is checked separately.
 */
final class AssetCreatePermissionTest extends TestCase
{
    /** @return callable(string): bool an oracle that grants exactly the listed permissions */
    private function granting(string ...$permissions): callable
    {
        return static fn(string $permission): bool => in_array($permission, $permissions, true);
    }

    public function testAllPermissionsPresentIsNotAnError(): void
    {
        $this->assertNull(CraftResolver::assetCreatePermissionError(
            $this->granting('saveAssets:vol-uid', 'createFolders:vol-uid'),
            'images',
            'vol-uid',
            false,
        ));
    }

    /** The permission name must match Craft's exactly, including the volume UID. */
    public function testMissingSaveAssetsIsRefusedAndNamesTheVolume(): void
    {
        $error = CraftResolver::assetCreatePermissionError(
            $this->granting('saveAssets:some-other-volume'),
            'images',
            'vol-uid',
            true,
        );

        $this->assertNotNull($error);
        $this->assertStringContainsString('images', $error);
        $this->assertStringContainsString('save assets', $error);
    }

    /** Uploading into an existing folder needs only `saveAssets`, as in Craft itself. */
    public function testCreateFoldersIsRequiredOnlyWhenTheFolderIsMissing(): void
    {
        $saveOnly = $this->granting('saveAssets:vol-uid');

        $this->assertNull(
            CraftResolver::assetCreatePermissionError($saveOnly, 'images', 'vol-uid', true),
            'an existing folder must not require createFolders',
        );

        $error = CraftResolver::assetCreatePermissionError($saveOnly, 'images', 'vol-uid', false);
        $this->assertNotNull($error);
        $this->assertStringContainsString('create folders', $error);
    }

    /** Without a user, nobody's permissions can authorize the write, so it is refused. */
    public function testNoUserContextIsRefusedRatherThanAllowed(): void
    {
        $error = CraftResolver::assetCreatePermissionError(null, 'images', 'vol-uid', true);

        $this->assertNotNull($error);
        $this->assertStringContainsString('no CP user', $error);
    }

    /**
     * An asset reference that matches nothing reaches the create step and is refused there
     * for lack of a user, before any Craft call (this test has no Craft app).
     *
     * The reference has no `volume` key, so the natural-key step is skipped without a query.
     */
    public function testAssetRefWithNoImportingUserIsRefusedBeforeAnyCraftCall(): void
    {
        $resolver = new RecordingResolver();
        $resolver->idForUid = null; // no canonical holds this UID here
        $this->assertTrue($resolver->createMissingAssets, 'premise: auto-create is on by default');
        $this->assertNull($resolver->importingUser, 'premise: no user outside an import');

        $ref = Ref::make(
            'asset',
            'a-uid',
            ['folderPath' => 'gallery/', 'filename' => 'hero.jpg'],
            ['url' => 'https://cdn.example.com/hero.jpg'],
        );
        $report = new Report();

        $id = $resolver->resolveRef($ref, $report);

        $this->assertNull($id);
        $this->assertSame([$ref], $report->unresolved, 'a refused create is reported, never silent');
        $this->assertNotEmpty($report->warnings);
        $this->assertStringContainsString('Asset create refused for hero.jpg', $report->warnings[0]);
        $this->assertStringContainsString('Nothing was downloaded', $report->warnings[0]);
    }
}
