<?php
/**
 * PHPUnit bootstrap for MoodleManagement plugin tests.
 * Registers a PSR-4-like autoloader for FacturaScripts namespaces.
 */

define('FS_FOLDER', dirname(__DIR__, 3));

// Load composer autoloader (for PHPUnit itself)
require_once FS_FOLDER . '/vendor/autoload.php';

// Register FS-style autoloader
spl_autoload_register(function (string $class): void {
    // FacturaScripts\Core\* → Core/*
    // FacturaScripts\Dinamic\* → Dinamic/*
    // FacturaScripts\Plugins\* → Plugins/*
    // FacturaScripts\Test\Plugins\* → Plugins/*/Test/*
    $prefix = 'FacturaScripts\\';
    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $path = FS_FOLDER . '/' . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($path)) {
        require_once $path;
        return;
    }

    // Try Dinamic/ bridge for Core classes
    if (strpos($relative, 'Core\\') === 0) {
        $dinamicPath = FS_FOLDER . '/Dinamic/' . str_replace('\\', '/', substr($relative, 5)) . '.php';
        if (file_exists($dinamicPath)) {
            require_once $dinamicPath;
            return;
        }
    }
});

// Load FS config for constants needed by models
$configFile = FS_FOLDER . '/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}
