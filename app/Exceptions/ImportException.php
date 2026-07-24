<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * SPEC-011 §ERROR HANDLING — a typed, user-safe failure raised anywhere in the
 * import pipeline. The controller maps `code` to an HTTP status and surfaces the
 * friendly message; internal detail is logged, never exposed.
 */
class ImportException extends RuntimeException
{
    public const INVALID_URL = 'invalid_url';
    public const NOT_FOUND = 'not_found';
    public const API_ERROR = 'api_error';
    public const RATE_LIMIT = 'rate_limit';
    public const TIMEOUT = 'timeout';
    public const NOT_CONFIGURED = 'not_configured';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function invalidUrl(): self
    {
        return new self(self::INVALID_URL, 'That doesn\'t look like a valid Google Business Profile URL. Please check and try again.');
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, 'We couldn\'t find a business at that link. Please verify the URL.');
    }

    public static function apiError(): self
    {
        return new self(self::API_ERROR, 'Google couldn\'t be reached right now. Please try again in a moment.');
    }

    public static function rateLimit(): self
    {
        return new self(self::RATE_LIMIT, 'Too many imports in a short time. Please wait a minute and try again.');
    }

    public static function timeout(): self
    {
        return new self(self::TIMEOUT, 'The request timed out. Please try again.');
    }

    public static function notConfigured(): self
    {
        return new self(self::NOT_CONFIGURED, 'Business import is not configured on this server yet.');
    }

    /** Map the reason to an appropriate HTTP status code. */
    public function status(): int
    {
        return match ($this->reason) {
            self::INVALID_URL => 422,
            self::NOT_FOUND => 404,
            self::RATE_LIMIT => 429,
            self::NOT_CONFIGURED => 503,
            self::TIMEOUT => 504,
            default => 502,
        };
    }
}
