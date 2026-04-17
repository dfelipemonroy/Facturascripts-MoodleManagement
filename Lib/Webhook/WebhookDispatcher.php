<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Webhook;

use FacturaScripts\Core\Tools;
use FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\Handler\CourseCompletedHandler;
use FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\Handler\EnrolmentCreatedHandler;
use FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\Handler\EnrolmentDeletedHandler;
use FacturaScripts\Plugins\MoodleManagement\Lib\Webhook\Handler\UserUpdatedHandler;
use FacturaScripts\Plugins\MoodleManagement\Model\MoodleInstance;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F10.1 · §6.14
 *
 * Routes a verified webhook payload to the matching handler. Each
 * handler is a pure function of the payload + instance: it must not
 * touch the HTTP response (that is the controller's job).
 *
 * Supported event types (must match Moodle Event API observer names):
 *   - enrolment_created
 *   - enrolment_deleted
 *   - course_completed
 *   - user_updated
 *
 * Unknown events return status "ignored" so operators can add new
 * event subscriptions in Moodle without FS breakage.
 */
final class WebhookDispatcher
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_IGNORED  = 'ignored';
    public const STATUS_ERROR    = 'error';

    /**
     * @param array $payload Decoded JSON body
     * @return array {status: string, message?: string}
     */
    public static function dispatch(MoodleInstance $instance, string $eventType, array $payload): array
    {
        try {
            switch ($eventType) {
                case 'enrolment_created':
                    EnrolmentCreatedHandler::handle($instance, $payload);
                    return ['status' => self::STATUS_ACCEPTED];

                case 'enrolment_deleted':
                    EnrolmentDeletedHandler::handle($instance, $payload);
                    return ['status' => self::STATUS_ACCEPTED];

                case 'course_completed':
                    CourseCompletedHandler::handle($instance, $payload);
                    return ['status' => self::STATUS_ACCEPTED];

                case 'user_updated':
                    UserUpdatedHandler::handle($instance, $payload);
                    return ['status' => self::STATUS_ACCEPTED];

                default:
                    Tools::log()->notice('mm-webhook-unknown-event', [
                        'instance_id' => (int) $instance->id,
                        'event_type'  => $eventType,
                    ]);
                    return [
                        'status'  => self::STATUS_IGNORED,
                        'message' => 'event type not handled',
                    ];
            }
        } catch (\Throwable $e) {
            Tools::log()->error('mm-webhook-handler-failed', [
                'instance_id' => (int) $instance->id,
                'event_type'  => $eventType,
                'exception'   => get_class($e),
                'message'     => $e->getMessage(),
            ]);
            return [
                'status'  => self::STATUS_ERROR,
                'message' => 'handler raised an exception',
            ];
        }
    }

    private function __construct()
    {
    }
}
