<?php

namespace chaseburklund\entryporter\utilities;

use Craft;
use craft\base\Utility;
use chaseburklund\entryporter\web\assets\porter\PorterAsset;

class PorterUtility extends Utility
{
    public static function displayName(): string
    {
        return 'Entry Porter';
    }

    public static function id(): string
    {
        return 'entry-porter';
    }

    public static function icon(): ?string
    {
        return 'paste';
    }

    public static function contentHtml(): string
    {
        $view = Craft::$app->getView();
        $view->registerAssetBundle(PorterAsset::class);
        // Access to the utility is a separate permission from importing, so the import button
        // is only shown to users who can use it.
        $canImport = Craft::$app->getUser()->checkPermission('entryPorter-import');
        return $view->renderTemplate('entry-porter/_utility', ['canImport' => $canImport]);
    }
}
