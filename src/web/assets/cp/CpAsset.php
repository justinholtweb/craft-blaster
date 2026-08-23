<?php

namespace justinholtweb\blaster\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset as CraftCpAsset;

class CpAsset extends AssetBundle
{
    public $sourcePath = __DIR__ . '/dist';

    public $depends = [CraftCpAsset::class];

    public $js = ['blaster-cp.js'];

    public $css = ['blaster-cp.css'];
}
