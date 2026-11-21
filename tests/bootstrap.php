<?php

// A path installation can share a checkout with its own development dependencies.
$autoload = defined('PHPUNIT_COMPOSER_INSTALL') ? PHPUNIT_COMPOSER_INSTALL : null;
if ($autoload === null || !is_file(dirname($autoload) . '/yiisoft/yii2/Yii.php')) {
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
        $autoload = dirname(__DIR__, 3) . '/autoload.php';
    }
}

defined('YII_ENABLE_ERROR_HANDLER') or define('YII_ENABLE_ERROR_HANDLER', false);
require_once $autoload;
require_once dirname($autoload) . '/yiisoft/yii2/Yii.php';
