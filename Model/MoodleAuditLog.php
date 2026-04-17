<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;

/**
 * Append-only audit log entry.
 *
 * Schema lives in Table/moodle_audit_log.xml. The companion helper
 * {@see \FacturaScripts\Plugins\MoodleManagement\Lib\Audit} is the
 * single entry point for writing rows — callers should not
 * instantiate this class directly.
 *
 * Outcome vocabulary (keep small and stable):
 *   - 'ok'            action completed
 *   - 'forbidden'     authorization check refused
 *   - 'rate_limited'  throttled by RateLimiter
 *   - 'bad_signature' SignedUrl payload failed verification
 *   - 'error'         handler threw / upstream returned error
 *
 * @since 2.0 F4.4 · §4.18
 */
class MoodleAuditLog extends ModelClass
{
    use ModelTrait;

    /** @var int */
    public $id;

    /** @var string|null */
    public $operator_nick;

    /** @var string */
    public $action;

    /** @var string */
    public $outcome;

    /** @var string|null */
    public $target_type;

    /** @var int|null */
    public $target_id;

    /** @var string|null SHA-256 hex digest of sensitive payload fragments. */
    public $payload_hash;

    /** @var string|null */
    public $ip;

    /** @var string|null */
    public $user_agent;

    /** @var string */
    public $created_at;

    public function clear(): void
    {
        parent::clear();
        $this->outcome = 'ok';
        $this->created_at = date('Y-m-d H:i:s');
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'moodle_audit_log';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'action';
    }
}
