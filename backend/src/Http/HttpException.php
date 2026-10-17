<?php
declare(strict_types=1);

namespace Postvak\Http;

use RuntimeException;

/** An error with an HTTP status whose message is safe to show to the user. */
final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function badRequest(string $m = 'Bad request'): self { return new self(400, $m); }
    public static function unauthorized(string $m = 'Not logged in'): self { return new self(401, $m); }
    public static function forbidden(string $m = 'Forbidden'): self { return new self(403, $m); }
    public static function notFound(string $m = 'Not found'): self { return new self(404, $m); }
    public static function tooMany(string $m = 'Too many attempts, try again later'): self { return new self(429, $m); }
    public static function upstream(string $m = 'Mail server error'): self { return new self(502, $m); }
}
