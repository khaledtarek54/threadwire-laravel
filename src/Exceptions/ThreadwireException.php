<?php

namespace Threadwire\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Threadwire refused a request. getMessage() is Threadwire's own reason, in
 * plain words, ready to show or log ("This number is not connected.");
 * errorCode is its fixed code ("not_connected"), for a program to act on.
 *
 * A subclass names the common cases; catch this one for all of them.
 */
class ThreadwireException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors  for a 422, what was wrong with each field
     * @param  int|null  $retryAfter  seconds to wait before trying again, when Threadwire said
     * @param  string|null  $errorCode  Threadwire's fixed `code` for the refusal ("not_connected", "limit_reached"…), to act on; its words may change, this never does
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly array $errors = [],
        public readonly ?int $retryAfter = null,
        public readonly ?Response $response = null,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response): self
    {
        $message = $response->json('message');
        $message = is_string($message) && $message !== '' ? $message : "Threadwire answered {$response->status()}.";
        $errors = $response->json('errors');
        $errors = is_array($errors) ? $errors : [];
        $retryAfter = $response->header('Retry-After');
        $retryAfter = is_numeric($retryAfter) ? (int) $retryAfter : null;
        $code = $response->json('code');
        $code = is_string($code) && $code !== '' ? $code : null;

        $class = match ($response->status()) {
            401 => AuthenticationException::class,
            403 => ForbiddenException::class,
            404 => NotFoundException::class,
            409 => ConflictException::class,
            422 => ValidationException::class,
            429 => RateLimitedException::class,
            default => self::class,
        };

        return new $class($message, $response->status(), $errors, $retryAfter, $response, $code);
    }
}
