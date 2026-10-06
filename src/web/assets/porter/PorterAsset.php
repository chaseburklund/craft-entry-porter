<?php

namespace chaseburklund\entryporter\web\assets\porter;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class PorterAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['porter.js'];
        $this->css = ['porter.css'];
        parent::init();
    }
}
