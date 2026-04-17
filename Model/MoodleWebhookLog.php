<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Model;

use FacturaScripts\Core\Template\ModelClass;
use FacturaScripts\Core\Template\ModelTrait;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F10.1 · §6.14
 *
 * One row per webhook request landing on ApiMoodleWebhook, whether
 * it passed verification or not. Deliberately stores only the hash
 * of the payload (SHA-256) so GDPR purge is a single UPDATE, not a
 * scrub of thousands of rows.
 */
class MoodleWebhookLog extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var int|null FK to moodle_instances.id, null when unidentified */
    public $idinstance;

    /** @var string|null e.g. "enrolment_created", "course_completed" */
    public $event_type;

    /** @var string */
    public $received_at;

    /** @var string|null */
    public $ip;

    /** @var bool HMAC signature matched */
    public $signature_ok;

    /** @var bool request not replayed (timestamp within window) */
    public $timestamp_ok;

    /** @var bool nonce not seen before */
    public $nonce_ok;

    /** @var string|null one of: accepted, rejected, error, ignored */
    public $outcome;

    /** @var int|null */
    public $http_status;

    /** @var string|null SHA-256 hex of the raw request body */
    public $payload_hash;

    /** @var int|null */
    public $payload_bytes;

    /** @var string|null user-visible error summary (no stack traces) */
    public $error_message;

    public function clear(): void
    {
        parent::clear();
        $this->received_at = date('Y-m-d H:i:s');
        $this->signature_ok = false;
        $this->timestamp_ok = false;
        $this->nonce_ok = false;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'event_type';
    }

    public static function tableName(): string
    {
        return 'moodle_webhook_log';
    }
}
