<?php
declare(strict_types=1);

namespace Organilog;

/** An error that becomes a JSON response: HTTP status + stable machine code + human message. */
final class ApiError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
