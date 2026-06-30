<?php

/**
 * This file is part of MoodleManagement plugin for FacturaScripts
 * Copyright (C) 2026 Diego Felipe Monroy <dfelipe.monroyc@gmail.com>
 */

declare(strict_types=1);

namespace FacturaScripts\Plugins\MoodleManagement\Lib\Exception;

use RuntimeException;
use Throwable;

/**
 * Base exception for every error raised by the plugin's integration
 * layer. Every other exception in this namespace extends this one so
 * callers can catch the whole family with a single `catch`.
 *
 * All subclasses accept an optional structured context array that
 * survives through the exception chain (retrievable via getContext())
 * so logs and operator messages can show useful metadata without
 * stuffing it into the message string.
 *
 * @since 2.0
 */
class MoodleException extends RuntimeException
{
    /** @var array<string, mixed> */
    protected array $context;

    /**
     * @param string $message Human-readable summary (English).
     * @param array<string, mixed> $context Optional structured payload.
     * @param int $code Numeric code (default 0).
     * @param Throwable|null $previous Upstream cause.
     */
    public function __construct(
        string $message = '',
        array $context = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
