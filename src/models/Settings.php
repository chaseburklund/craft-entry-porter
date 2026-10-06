<?php

namespace chaseburklund\entryporter\models;

use craft\base\Model;

/**
 * Plugin settings, set in `config/entry-porter.php`:
 *
 *     <?php
 *     return [
 *         'createMissingAssets' => false,
 *     ];
 */
class Settings extends Model
{
    /**
     * Whether an asset that a payload references but that does not exist here may be created
     * by downloading it from the source environment. Downloads are limited to public http(s)
     * URLs, and creation to users with permission to save assets in the volume. When off, a
     * missing asset is listed as unresolved in the import report.
     */
    public bool $createMissingAssets = true;

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['createMissingAssets'], 'boolean'],
        ]);
    }
}
