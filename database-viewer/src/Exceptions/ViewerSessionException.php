<?php

namespace GreyHarbour\DatabaseViewer\Exceptions;

use RuntimeException;

final class ViewerSessionException extends RuntimeException
{
    private function __construct(
        string $message,
        private int $status,
        private ?string $codeName,
    ) {
        parent::__construct($message);
    }

    public static function mismatch(): self
    {
        return new self('Database Viewer session is invalid.', 403, null);
    }

    public static function expired(): self
    {
        return new self('Database Viewer session expired.', 410, 'SESSION_EXPIRED');
    }

    public static function closed(): self
    {
        return new self('Database Viewer session closed.', 410, 'SESSION_CLOSED');
    }

    public static function limitReached(): self
    {
        return new self('Too many active Database Viewer sessions.', 429, 'VIEWER_LIMIT_REACHED');
    }

    public function httpStatus(): int
    {
        return $this->status;
    }

    public function applicationCode(): ?string
    {
        return $this->codeName;
    }
}
