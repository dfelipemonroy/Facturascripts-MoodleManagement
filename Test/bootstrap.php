<?php

/**
 * PHPUnit bootstrap for MoodleManagement plugin.
 *
 * Loads the FacturaScripts autoloader so Core\ and Dinamic\ classes
 * resolve, plus plugin source paths via PSR-4.
 *
 * Running order:
 *   1. composer autoload (if plugin has its own vendor/)
 *   2. FS core autoload (two levels up)
 *   3. Register plugin PSR-4 roots manually for tests that don't need
 *      the full framework boot.
 *
 * @since 2.0
 */

declare(strict_types=1);

// Plugin root — this file lives at Plugins/MoodleManagement/Test/bootstrap.php
$pluginRoot = dirname(__DIR__);
$fsRoot = dirname($pluginRoot, 2); // ../../ → FacturaScripts root

// 1. Plugin-local composer autoload if present.
//
//    F14 caveat: when BOTH the plugin's own vendor (with phpunit 9) AND
//    the FS core vendor (with phpunit 11) are present, loading the
//    plugin vendor FIRST wins for phpunit classes and avoids a
//    TestSuite::__construct visibility collision. Always load the
//    plugin vendor before FS core.
$pluginVendor = $pluginRoot . '/vendor/autoload.php';
if (is_file($pluginVendor)) {
    require_once $pluginVendor;
}

// 2. FacturaScripts core composer autoload.
$fsVendor = $fsRoot . '/vendor/autoload.php';
if (is_file($fsVendor)) {
    require_once $fsVendor;
} else {
    fwrite(STDERR, "[bootstrap] FS core vendor not found at {$fsVendor}\n");
    fwrite(STDERR, "[bootstrap] Tests that rely on FS core will fail.\n");
}

// 3. Minimal PSR-4 register for plugin classes so unit tests don't require
//    PluginsDeploy to have run.
spl_autoload_register(static function (string $class) use ($pluginRoot, $fsRoot): void {
    // Map: FacturaScripts\Plugins\MoodleManagement\* -> Plugins/MoodleManagement/*
    $ownPrefix = 'FacturaScripts\\Plugins\\MoodleManagement\\';
    if (strncmp($class, $ownPrefix, strlen($ownPrefix)) === 0) {
        $rel = substr($class, strlen($ownPrefix));
        $path = $pluginRoot . '/' . str_replace('\\', '/', $rel) . '.php';
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }

    // Map: FacturaScripts\Test\* -> Test/ under plugin (our own tests)
    $testPrefix = 'FacturaScripts\\Test\\Plugins\\MoodleManagement\\';
    if (strncmp($class, $testPrefix, strlen($testPrefix)) === 0) {
        $rel = substr($class, strlen($testPrefix));
        $path = $pluginRoot . '/Test/' . str_replace('\\', '/', $rel) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

// Define FS_FOLDER for tests that inspect paths.
if (!defined('FS_FOLDER')) {
    define('FS_FOLDER', $fsRoot);
}

// Default timezone for deterministic test runs.
date_default_timezone_set('UTC');

// Deterministic TokenCipher key for the test suite. `FS_COOKIES_EXPIRE`
// is normally defined in FS `config.php`, which the plugin test bootstrap
// does not load. Expose a stable value via the environment so crypto-
// related tests are reproducible. The fail-closed regression test
// (SEC-01) unsets this env var to assert the RuntimeException path.
if (getenv('FS_COOKIES_EXPIRE') === false) {
    putenv('FS_COOKIES_EXPIRE=mm-test-cookie-secret-do-not-use-in-prod');
}

// Silence deprecation noise from third-party vendored code during tests.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
