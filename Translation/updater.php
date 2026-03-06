<?php
/**
 * Translation key synchronizer for MoodleManagement plugin.
 *
 * Ensures all language files have the same keys as en_EN.json (reference).
 * - Spanish variants (es_*) get missing values from es_ES.json.
 * - Other languages get missing values from en_EN.json (English fallback).
 *
 * Usage: php updater.php
 */
if (php_sapi_name() !== "cli") {
    die("Please use command line: php updater.php");
}

chdir(__DIR__);

$enFile = 'en_EN.json';
$esFile = 'es_ES.json';

if (!file_exists($enFile) || !file_exists($esFile)) {
    die("Reference files (en_EN.json / es_ES.json) not found.\n");
}

$en = json_decode(file_get_contents($enFile), true);
$es = json_decode(file_get_contents($esFile), true);

if (empty($en) || empty($es)) {
    die("Failed to parse reference files.\n");
}

// sort reference files
ksort($en);
ksort($es);
file_put_contents($enFile, json_encode($en, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
file_put_contents($esFile, json_encode($es, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");

$spanishVariants = ['es_AR', 'es_CL', 'es_CO', 'es_CR', 'es_DO', 'es_EC', 'es_GT', 'es_MX', 'es_PA', 'es_PE', 'es_UY'];

$updated = 0;
foreach (scandir(__DIR__, SCANDIR_SORT_ASCENDING) as $filename) {
    if (!is_file($filename) || substr($filename, -5) !== '.json') {
        continue;
    }
    if ($filename === $enFile || $filename === $esFile) {
        continue;
    }

    $data = json_decode(file_get_contents($filename), true);
    if (!is_array($data)) {
        echo "SKIP $filename (invalid JSON)\n";
        continue;
    }

    $lang = substr($filename, 0, -5);
    $isSpanish = in_array($lang, $spanishVariants) || (strpos($lang, 'es_') === 0);
    $fallback = $isSpanish ? $es : $en;

    $added = 0;
    foreach ($en as $key => $value) {
        if (!isset($data[$key])) {
            $data[$key] = $fallback[$key] ?? $value;
            $added++;
        }
    }

    // remove keys not in reference
    $removed = 0;
    foreach (array_keys($data) as $key) {
        if (!isset($en[$key])) {
            unset($data[$key]);
            $removed++;
        }
    }

    if ($added > 0 || $removed > 0) {
        ksort($data);
        file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
        echo "UPDATE $filename (+$added / -$removed)\n";
        $updated++;
    } else {
        echo "OK     $filename\n";
    }
}

echo "\nDone. $updated file(s) updated. Reference: " . count($en) . " keys.\n";
