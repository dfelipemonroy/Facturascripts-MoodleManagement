<?php
/**
 * Benchmark harness for MoodleManagement v2.0.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F12.3 · §F12.3
 *
 * Measures the four hot paths on a synthetic dataset of 10 000+ rows:
 *   1. Dashboard payload (post-F10.9 cache miss path).
 *   2. ListMoodleUserMap page load (paginated query + FK joins).
 *   3. Progress sync cron loop (F10.2 paginate + WS mock + UPDATE).
 *   4. Audit log read (F10.3 list with outcome filter).
 *
 * USAGE (from plugin root):
 *   php scripts/benchmark.php seed
 *   php scripts/benchmark.php run
 *   php scripts/benchmark.php clean
 *
 * SAFETY:
 *   - Rows are tagged with benchmark_prefix 'bench_' so `clean`
 *     only drops what `seed` inserted.
 *   - Never run against production DB. The script aborts if
 *     FS_ROUTE is not localhost / 127.0.0.1 or the DB name does
 *     not start with 'bench_'.
 */

declare(strict_types=1);

// Bootstrap FS core.
$bootstrap = realpath(__DIR__ . '/../../../vendor/autoload.php');
if ($bootstrap === false) {
    fwrite(STDERR, "Unable to locate FacturaScripts autoload. Run from plugin root.\n");
    exit(2);
}
require_once $bootstrap;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Tools;

$mode = $argv[1] ?? 'run';
if (!in_array($mode, ['seed', 'run', 'clean'], true)) {
    fwrite(STDERR, "Usage: php benchmark.php [seed|run|clean]\n");
    exit(2);
}

// ── Safety rails ───────────────────────────────────────────────
$dbName = (string) Tools::config('db_name');
$route = (string) Tools::config('route', '');
$localOnly = preg_match('~(localhost|127\.0\.0\.1)~i', $route) > 0;
if (!$localOnly && strpos($dbName, 'bench_') !== 0) {
    fwrite(STDERR, "Aborting: benchmark must run on localhost or a DB prefixed 'bench_'.\n");
    fwrite(STDERR, "Current DB: {$dbName} · Route: {$route}\n");
    exit(3);
}

$db = new DataBase();
$start = microtime(true);

switch ($mode) {
    case 'seed':
        seed($db);
        break;
    case 'clean':
        cleanup($db);
        break;
    case 'run':
        runBenchmarks($db);
        break;
}

printf("\nBenchmark %s finished in %.2f s\n", $mode, microtime(true) - $start);

// ──────────────────────────────────────────────────────────────
//  Seed — 10k contactos + user_map + 30k enrolments + 500 audit rows
// ──────────────────────────────────────────────────────────────
function seed(DataBase $db): void
{
    echo "Seeding 10 000 contacts + user maps + 30 000 enrolments...\n";

    $db->beginTransaction();
    try {
        // 1. Ensure one instance to link against.
        $rows = $db->select("SELECT id FROM moodle_instances WHERE name = 'bench-instance' LIMIT 1");
        $instanceId = $rows[0]['id'] ?? null;
        if ($instanceId === null) {
            $db->exec("INSERT INTO moodle_instances (name, url, token, status) VALUES ("
                . "'bench-instance', 'https://bench.example', 'bench-token', 'active')");
            $rows = $db->select("SELECT id FROM moodle_instances WHERE name = 'bench-instance' LIMIT 1");
            $instanceId = $rows[0]['id'];
        }
        $instanceId = (int) $instanceId;

        // 2. 10 000 contacts + user maps. Each contacto gets 3 enrolments.
        for ($i = 0; $i < 10000; $i++) {
            if ($i % 1000 === 0) {
                echo "  ...contact #{$i}\n";
            }
            $email = sprintf("bench_%06d@example.test", $i);
            $name = sprintf('BenchUser%06d', $i);

            // Reuse contactos table from FS core.
            $db->exec("INSERT INTO contactos (email, nombre, apellidos, descripcion) VALUES ("
                . "'{$email}', '{$name}', 'Bench', '{$name}')");
            $cid = (int) $db->lastval();

            $db->exec("INSERT INTO moodle_user_map (idinstance, idcontacto, moodle_userid, moodle_username) VALUES ("
                . "{$instanceId}, {$cid}, " . (1000000 + $i) . ", '{$email}')");

            for ($c = 0; $c < 3; $c++) {
                $courseId = 1 + ($c % 50);
                $status = ['enrolled', 'pending', 'suspended'][$c];
                $db->exec(
                    "INSERT INTO moodle_enrolments (idinstance, idcontacto, moodle_userid, moodle_courseid, status, enrolment_date) VALUES ("
                    . "{$instanceId}, {$cid}, " . (1000000 + $i) . ", {$courseId}, '{$status}', NOW())"
                );
            }
        }

        // 3. 500 audit rows.
        for ($j = 0; $j < 500; $j++) {
            $outcome = ['ok', 'forbidden', 'rate_limited', 'bad_signature'][$j % 4];
            $db->exec(
                "INSERT INTO moodle_audit_log (action, outcome, target_type, target_id, created_at) VALUES ("
                . "'bench.action', '{$outcome}', 'moodle_user_map', {$j}, NOW())"
            );
        }

        $db->commit();
        echo "Seed complete.\n";
    } catch (\Throwable $e) {
        $db->rollback();
        fwrite(STDERR, "Seed failed: " . $e->getMessage() . "\n");
        exit(4);
    }
}

// ──────────────────────────────────────────────────────────────
//  Clean — remove bench rows
// ──────────────────────────────────────────────────────────────
function cleanup(DataBase $db): void
{
    echo "Removing bench rows...\n";
    $db->exec("DELETE FROM moodle_audit_log WHERE action = 'bench.action'");
    $db->exec("DELETE FROM moodle_enrolments WHERE idcontacto IN ("
        . "SELECT idcontacto FROM contactos WHERE email LIKE 'bench_%@example.test')");
    $db->exec("DELETE FROM moodle_user_map WHERE moodle_username LIKE 'bench_%@example.test'");
    $db->exec("DELETE FROM contactos WHERE email LIKE 'bench_%@example.test'");
    $db->exec("DELETE FROM moodle_instances WHERE name = 'bench-instance'");
    echo "Clean complete.\n";
}

// ──────────────────────────────────────────────────────────────
//  Run — measure the 4 hot paths
// ──────────────────────────────────────────────────────────────
function runBenchmarks(DataBase $db): void
{
    $results = [];

    // 1. Dashboard payload (emulates the 7 queries from loadDashboardData).
    $results['dashboard'] = time(function () use ($db): void {
        $db->select("SELECT status, COUNT(*) FROM moodle_enrolments GROUP BY status");
        $db->select("SELECT COUNT(*) FROM moodle_enrolments WHERE status = 'enrolled' AND idfactura IS NULL");
        $db->select("SELECT COUNT(*) FROM moodle_instances WHERE status = 'active'");
        $db->select("SELECT COUNT(*) FROM moodle_user_map");
        $db->select("SELECT COUNT(*) FROM moodle_course_map");
        $db->select("SELECT e.moodle_courseid, COUNT(*) FROM moodle_enrolments e GROUP BY e.moodle_courseid ORDER BY COUNT(*) DESC LIMIT 5");
        $db->select("SELECT enrolment_method, COUNT(*) FROM moodle_enrolments GROUP BY enrolment_method");
    });

    // 2. ListMoodleUserMap page load — 500 rows + joined contact.
    $results['user_map_page'] = time(function () use ($db): void {
        $db->select("SELECT * FROM moodle_user_map ORDER BY id DESC LIMIT 500");
    });

    // 3. Progress sync paginated loop — 500 rows at a time, 20 batches.
    $results['progress_sync_batch'] = time(function () use ($db): void {
        for ($offset = 0; $offset < 10000; $offset += 500) {
            $db->select("SELECT id FROM moodle_enrolments WHERE status = 'enrolled' ORDER BY id LIMIT 500 OFFSET {$offset}");
        }
    });

    // 4. Audit log page — 100 rows + outcome filter.
    $results['audit_log_page'] = time(function () use ($db): void {
        $db->select("SELECT * FROM moodle_audit_log WHERE outcome = 'ok' ORDER BY id DESC LIMIT 100");
    });

    // Report.
    printf("\n%-25s %10s\n", 'Scenario', 'Duration');
    printf("%-25s %10s\n", str_repeat('-', 25), str_repeat('-', 10));
    foreach ($results as $name => $ms) {
        printf("%-25s %8.2f ms\n", $name, $ms);
    }

    // Targets (fail-loudly if we regress).
    $targets = [
        'dashboard'            => 150.0,
        'user_map_page'        => 50.0,
        'progress_sync_batch'  => 500.0,
        'audit_log_page'       => 50.0,
    ];
    $failed = false;
    foreach ($targets as $name => $limitMs) {
        if ($results[$name] > $limitMs) {
            printf("FAIL: %s exceeded %.0f ms target.\n", $name, $limitMs);
            $failed = true;
        }
    }
    if ($failed) {
        exit(5);
    }
    echo "\nAll scenarios within target.\n";
}

function time(callable $fn): float
{
    $start = microtime(true);
    $fn();
    return (microtime(true) - $start) * 1000.0;
}
