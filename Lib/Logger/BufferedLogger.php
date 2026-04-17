<?php
/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Logger;

use FacturaScripts\Core\Tools;

/**
 * @since 2.0 — V2.0-ACTION-PLAN F10.10 · §6.23
 *
 * Accumulates log events in memory and emits them through
 * Tools::log() in compact bursts.
 *
 * Rationale:
 *   Tools::log() persists each call through the FS logger stack.
 *   The Cron jobs (progressSync, userSync, reconciliation) and
 *   EnrolmentWorker can emit hundreds of near-identical lines per
 *   run; forwarding every single call costs disk I/O plus
 *   serialization overhead. Buffering into a single compound
 *   record per level (notice/warning/error) drops that to at most
 *   one Tools::log() call per level per flush cycle.
 *
 * Usage:
 *   $log = new BufferedLogger('progress-sync', 100); // flush every 100 events
 *   $log->notice('progress-updated', ['id' => $row->id]);
 *   ...
 *   $log->flush(); // mandatory at the end of the hot loop
 *
 * The buffer also auto-flushes in its destructor, so forgetting
 * the explicit flush() does not lose log lines — at worst, they
 * arrive slightly later, grouped by PHP-FPM request teardown.
 *
 * Not thread-safe. Each worker/cron should instantiate its own.
 */
final class BufferedLogger
{
    /** Logical channel passed to Tools::log(). */
    private string $channel;

    /** Max events per level before an implicit flush happens. */
    private int $batchSize;

    /**
     * Pending events, keyed by level → array of [message, context].
     *
     * @var array<string, array<int, array{0:string,1:array}>>
     */
    private array $pending = [
        'notice' => [],
        'warning' => [],
        'error' => [],
    ];

    public function __construct(string $channel = '', int $batchSize = 100)
    {
        $this->channel = $channel;
        $this->batchSize = $batchSize > 0 ? $batchSize : 100;
    }

    public function notice(string $message, array $context = []): void
    {
        $this->push('notice', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->push('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->push('error', $message, $context);
    }

    /**
     * Emit every pending event. Safe to call multiple times; the
     * second call is a no-op unless new events arrived in between.
     */
    public function flush(): void
    {
        foreach ($this->pending as $level => $events) {
            if (empty($events)) {
                continue;
            }
            $this->emit($level, $events);
            $this->pending[$level] = [];
        }
    }

    public function __destruct()
    {
        $this->flush();
    }

    // ─── Internals ────────────────────────────────────────────────

    private function push(string $level, string $message, array $context): void
    {
        $this->pending[$level][] = [$message, $context];
        if (count($this->pending[$level]) >= $this->batchSize) {
            $this->emit($level, $this->pending[$level]);
            $this->pending[$level] = [];
        }
    }

    /**
     * Collapse multiple events into a single Tools::log() call. The
     * composite message carries the first event's tag plus the
     * total count; the per-event context is bundled as a flat list
     * so operators can still drill down through the structured log.
     */
    private function emit(string $level, array $events): void
    {
        if (empty($events)) {
            return;
        }
        $first = $events[0][0];
        $count = count($events);
        $logger = Tools::log($this->channel);

        if ($count === 1) {
            // Single event — no aggregation needed, forward as-is.
            $logger->{$level}($first, $events[0][1]);
            return;
        }

        $context = [
            'first'   => $first,
            'count'   => $count,
            'events'  => array_map(static function (array $ev): array {
                return ['msg' => $ev[0], 'ctx' => $ev[1]];
            }, $events),
        ];
        $logger->{$level}('mm-batched-events', $context);
    }
}
