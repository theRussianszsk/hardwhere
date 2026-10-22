<?php

namespace App\Support;

use RuntimeException;

/** A domain failure the client can act on. Rendered as {"error":{"code","message","details"}}. */
class ApiException extends RuntimeException
{
    /** @param  array<string, mixed>  $details */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @param  array<string, mixed>  $details */
    public static function conflict(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 409, $details);
    }

    public static function forbidden(string $message = 'This action is not allowed for your role.'): self
    {
        return new self('forbidden', $message, 403);
    }

    public static function notFound(string $what = 'Resource'): self
    {
        return new self('not_found', "$what was not found.", 404);
    }

    /** @param  array<string, mixed>  $details */
    public static function invalid(string $code, string $message, array $details = []): self
    {
        return new self($code, $message, 422, $details);
    }
}
