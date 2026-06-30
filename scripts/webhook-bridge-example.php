<?php
/**
 * Moodle → FacturaScripts webhook bridge — reference implementation.
 *
 * @since 2.0 — V2.0-ACTION-PLAN F13 · DISCOVERED-01
 *
 * Moodle core does not emit HTTP webhooks natively. Operators who
 * want the `/ApiMoodleWebhook` receiver (F10.1) to react to Moodle
 * events must wire up a bridge: an Event Observer on the Moodle
 * side that invokes this script (or equivalent) whenever a
 * whitelisted event fires.
 *
 * This file is NOT loaded by FacturaScripts at runtime. It is a
 * standalone CLI snippet meant to be copied onto the Moodle host,
 * customised with the instance credentials, and driven from a
 * local event observer. Deliberately dependency-free (only ext-curl
 * + ext-openssl) so it drops into any Moodle host.
 *
 * Usage from a Moodle Event Observer:
 *   \local_yourplugin\bridge::send('enrolment_created', [
 *       'userid'   => $event->relateduserid,
 *       'courseid' => $event->courseid,
 *       'timestart'=> (int) $event->timecreated,
 *   ]);
 *
 * which in turn shells out to:
 *   php /path/to/webhook-bridge-example.php <event> <json-payload>
 *
 * SECURITY NOTES
 *   - $SECRET below MUST match moodle_instances.webhook_secret on
 *     FacturaScripts (the plaintext value, not the cipher-wrapped
 *     form). TokenCipher unwraps it server-side.
 *   - The nonce is 32 hex chars from random_bytes; do not reuse it.
 *   - Timestamp is Unix epoch, must be within ±5 minutes of the FS
 *     server clock. Keep both hosts on NTP.
 *   - HTTPS only. Plain HTTP is rejected by the FS proxy layer.
 */

declare(strict_types=1);

// ─── Configuration (replace with real values) ─────────────────────
$FS_BASE_URL   = 'https://staging.example.test';
$INSTANCE_ID   = 1;
$SECRET        = 'replace-with-plaintext-webhook-secret';
$TIMEOUT_SECS  = 10;

// ─── CLI argument parsing ────────────────────────────────────────
$event   = $argv[1] ?? null;
$payload = $argv[2] ?? null;

if ($event === null || $payload === null) {
    fwrite(STDERR, "Usage: php webhook-bridge-example.php <event-type> '<json-payload>'\n");
    fwrite(STDERR, "Supported events: enrolment_created, enrolment_deleted, course_completed, user_updated\n");
    exit(2);
}

// Validate event type client-side so we don't waste a round trip.
$allowed = ['enrolment_created', 'enrolment_deleted', 'course_completed', 'user_updated'];
if (!in_array($event, $allowed, true)) {
    fwrite(STDERR, "Unknown event type: {$event}\n");
    exit(2);
}

// Validate JSON — malformed payloads are rejected upstream with 400.
$decoded = json_decode($payload, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "Invalid JSON payload.\n");
    exit(2);
}

// ─── Canonicalise body (deterministic order — server signs same) ─
// json_encode with JSON_UNESCAPED_SLASHES matches the FS side's
// signing convention; no sorted keys needed (the receiver signs
// raw bytes of whatever arrives).
$body = (string) json_encode($decoded, JSON_UNESCAPED_SLASHES);

$timestamp = (string) time();
try {
    $nonce = bin2hex(random_bytes(16));
} catch (\Throwable $e) {
    // Fallback — acceptable because the server still enforces HMAC
    // + 5-min window; a weak nonce only degrades replay protection.
    $nonce = substr(sha1((string) microtime(true) . (string) getmypid()), 0, 32);
}

$signature = hash_hmac('sha256', $body, $SECRET);

// ─── Send ────────────────────────────────────────────────────────
$url = rtrim($FS_BASE_URL, '/') . '/ApiMoodleWebhook?instance=' . $INSTANCE_ID;
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'X-MM-Event: ' . $event,
        'X-MM-Timestamp: ' . $timestamp,
        'X-MM-Nonce: ' . $nonce,
        'X-MM-Signature: sha256=' . $signature,
        'Content-Length: ' . strlen($body),
    ],
    CURLOPT_TIMEOUT        => $TIMEOUT_SECS,
    CURLOPT_RETURNTRANSFER => true,
    // Belt and braces — refuse HTTP redirects to avoid smuggling
    // attempts. If the receiver relocates, update FS_BASE_URL.
    CURLOPT_FOLLOWLOCATION => false,
    // Mandatory TLS verification; never disable in production.
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);
$status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err      = curl_error($ch);
curl_close($ch);

if ($response === false) {
    fwrite(STDERR, "cURL error: {$err}\n");
    exit(3);
}

echo "HTTP {$status}\n";
echo (string) $response . "\n";

// Exit non-zero on non-2xx so the caller (cron / event observer
// wrapper) can react — retry, alert, etc.
if ($status < 200 || $status >= 300) {
    exit(1);
}
