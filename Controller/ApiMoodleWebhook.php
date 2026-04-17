<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Audit;
use FacturaScripts\Plugins\MoodleManagement\Lib\Security\RateLimiter;
use FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\WebhookDispatcher;
use FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\WebhookVerifier;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleWebhookLog;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F10.1 · §6.14
 *
 * Public webhook receiver for Moodle → FS event notifications.
 *
 * URL:
 *   POST /ApiMoodleWebhook?instance=<id>
 *
 * Required headers:
 *   X-MM-Signature: sha256=<hex>       HMAC-SHA256 over raw body
 *   X-MM-Timestamp: <unix_epoch>        request creation time
 *   X-MM-Nonce:     <opaque>            random id, unique per request
 *   X-MM-Event:     <event_type>        enrolment_created | … | user_updated
 *
 * Flow:
 *   1. Rate-limit by remote IP.
 *   2. Load the MoodleInstance (reject 404 if unknown).
 *   3. Verify HMAC against the per-instance webhook_secret.
 *   4. Verify timestamp within TIMESTAMP_WINDOW.
 *   5. Register nonce (replay protection).
 *   6. Decode JSON body and dispatch to the matching handler.
 *   7. Persist MoodleWebhookLog + Audit::record() in all paths.
 *   8. Respond with JSON {status, message?}.
 *
 * Return codes:
 *   200 — accepted by a handler
 *   202 — accepted but event type is unhandled (status=ignored)
 *   400 — malformed request (missing headers, bad JSON)
 *   401 — signature mismatch
 *   403 — unknown instance or disabled webhooks
 *   409 — replayed nonce or stale timestamp
 *   429 — rate limited
 *   500 — handler raised
 */
class ApiMoodleWebhook extends Controller
{
    /** Rate bucket name. */
    private const RATE_BUCKET = 'webhook.receive';

    /** Max calls per minute per IP. */
    private const RATE_LIMIT = 120;

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';   // hidden from UI, admin-scoped
        $data['title'] = 'moodle-webhook';
        $data['icon'] = 'fa-solid fa-arrow-right-arrow-left';
        return $data;
    }

    /**
     * We expose the endpoint as fully public (no FS login required)
     * because Moodle has no way of carrying FS session cookies. The
     * HMAC check is the real authentication boundary.
     */
    public function publicCore(&$response): void
    {
        parent::publicCore($response);
        $this->handle();
    }

    /**
     * privateCore is reachable only when the operator hits the URL
     * while logged into FS. Route to the same handler — a logged-in
     * admin can use cURL/Postman for smoke tests without special
     * scaffolding.
     */
    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);
        $this->handle();
    }

    // ────────────────────────────────────────────────────────────────
    //  Actual receive pipeline
    // ────────────────────────────────────────────────────────────────
    private function handle(): void
    {
        $log = new MoodleWebhookLog();
        $log->ip = (string) $this->request->getClientIp();

        // 1. Rate-limit by remote IP.
        $ip = $log->ip ?: 'unknown';
        if (!RateLimiter::check($ip, self::RATE_BUCKET, self::RATE_LIMIT)) {
            $log->outcome = 'rate_limited';
            $this->saveLog($log, 429, 'too-many-requests');
            $this->respond(429, ['status' => 'rate_limited']);
            return;
        }

        // 2. Accept only POST.
        if (strtoupper($this->request->getMethod()) !== 'POST') {
            $this->saveLog($log, 405, 'method-not-allowed');
            $this->respond(405, ['status' => 'method_not_allowed']);
            return;
        }

        // 3. Basic header extraction.
        $instanceId = (int) $this->request->get('instance', 0);
        $sigHeader = (string) $this->request->headers->get('X-MM-Signature', '');
        $timestamp = (int) $this->request->headers->get('X-MM-Timestamp', '0');
        $nonce = (string) $this->request->headers->get('X-MM-Nonce', '');
        $eventType = (string) $this->request->headers->get('X-MM-Event', '');

        $log->idinstance = $instanceId > 0 ? $instanceId : null;
        $log->event_type = $eventType !== '' ? substr($eventType, 0, 60) : null;

        if ($instanceId <= 0 || $sigHeader === '' || $timestamp <= 0 || $nonce === '' || $eventType === '') {
            $this->saveLog($log, 400, 'missing-headers');
            $this->respond(400, ['status' => 'bad_request', 'message' => 'missing required headers']);
            return;
        }

        // 4. Raw body (once — Symfony request can read it multiple times).
        $rawBody = (string) $this->request->getContent();
        $log->payload_bytes = strlen($rawBody);
        $log->payload_hash = hash('sha256', $rawBody);

        // 5. Load the instance.
        $instance = new MoodleInstance();
        if (false === $instance->loadFromCode((string) $instanceId)) {
            $this->saveLog($log, 403, 'unknown-instance');
            $this->respond(403, ['status' => 'forbidden']);
            return;
        }

        // 6. webhook_secret must be provisioned in moodle_instances.
        $rawSecret = property_exists($instance, 'webhook_secret')
            ? (string) $instance->webhook_secret
            : '';
        $secret = WebhookVerifier::resolveSecret($rawSecret);
        if ($secret === '') {
            $this->saveLog($log, 403, 'webhook-not-provisioned');
            $this->respond(403, ['status' => 'forbidden', 'message' => 'webhook not configured']);
            return;
        }

        // 7. HMAC verification (strip optional "sha256=" prefix).
        $providedHex = $sigHeader;
        if (strpos($providedHex, 'sha256=') === 0) {
            $providedHex = substr($providedHex, 7);
        }
        $log->signature_ok = WebhookVerifier::verifySignature($rawBody, $secret, $providedHex);
        if (!$log->signature_ok) {
            Audit::record('webhook.receive', Audit::BAD_SIGNATURE, [
                'target_type' => 'moodle_instance',
                'target_id'   => $instanceId,
                'ip'          => $ip,
                'payload'     => ['event' => $eventType],
            ]);
            $this->saveLog($log, 401, 'bad-signature');
            $this->respond(401, ['status' => 'unauthorized']);
            return;
        }

        // 8. Timestamp freshness.
        $log->timestamp_ok = WebhookVerifier::verifyTimestamp($timestamp);
        if (!$log->timestamp_ok) {
            $this->saveLog($log, 409, 'stale-timestamp');
            $this->respond(409, ['status' => 'conflict', 'message' => 'stale timestamp']);
            return;
        }

        // 9. Nonce replay check.
        $log->nonce_ok = WebhookVerifier::registerNonce($nonce);
        if (!$log->nonce_ok) {
            $this->saveLog($log, 409, 'replayed-nonce');
            $this->respond(409, ['status' => 'conflict', 'message' => 'replayed nonce']);
            return;
        }

        // 10. JSON decode.
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $this->saveLog($log, 400, 'bad-json');
            $this->respond(400, ['status' => 'bad_request', 'message' => 'invalid JSON body']);
            return;
        }

        // 11. Dispatch.
        $outcome = WebhookDispatcher::dispatch($instance, $eventType, $payload);
        $status = $outcome['status'];
        $http = $status === WebhookDispatcher::STATUS_ACCEPTED ? 200
              : ($status === WebhookDispatcher::STATUS_IGNORED ? 202 : 500);

        $log->outcome = $status;
        $log->error_message = isset($outcome['message'])
            ? substr((string) $outcome['message'], 0, 500)
            : null;
        $this->saveLog($log, $http, null);

        Audit::record('webhook.receive', Audit::OK, [
            'target_type' => 'moodle_instance',
            'target_id'   => $instanceId,
            'ip'          => $ip,
            'payload'     => ['event' => $eventType, 'bytes' => $log->payload_bytes],
        ]);
        $this->respond($http, $outcome);
    }

    /**
     * Persist a MoodleWebhookLog row. Failure to persist is itself
     * logged but never breaks the HTTP response — the request has
     * already succeeded or failed by that point.
     */
    private function saveLog(MoodleWebhookLog $log, int $http, ?string $errorMsg): void
    {
        $log->http_status = $http;
        if ($errorMsg !== null && $log->outcome === null) {
            $log->outcome = 'rejected';
            $log->error_message = substr($errorMsg, 0, 500);
        }
        try {
            $log->save();
        } catch (\Throwable $e) {
            Tools::log()->warning('mm-webhook-log-save-failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Emit a JSON response. Always sets Content-Type and a short
     * Cache-Control to prevent intermediaries caching webhooks.
     */
    private function respond(int $http, array $body): void
    {
        $this->response->setStatusCode($http);
        $this->response->headers->set('Content-Type', 'application/json; charset=utf-8');
        $this->response->headers->set('Cache-Control', 'no-store');
        $this->response->setContent((string) json_encode($body, JSON_UNESCAPED_SLASHES));
    }
}
