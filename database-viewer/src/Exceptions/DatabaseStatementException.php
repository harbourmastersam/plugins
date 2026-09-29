<?php

namespace GreyHarbour\DatabaseViewer\Exceptions;

use PDOException;
use RuntimeException;

final class DatabaseStatementException extends RuntimeException
{
    private const MAX_DIAGNOSTIC_BYTES = 2048;

    private function __construct(private string $safeDiagnostic, PDOException $previous)
    {
        parent::__construct('Database statement failed.', 0, $previous);
    }

    public static function fromPdo(PDOException $exception, string $statement): self
    {
        $errorInfo = is_array($exception->errorInfo ?? null) ? $exception->errorInfo : [];
        $sqlState = isset($errorInfo[0]) && is_string($errorInfo[0])
            ? preg_replace('/[^A-Z0-9]/i', '', $errorInfo[0])
            : null;
        $vendorCode = isset($errorInfo[1]) && (is_int($errorInfo[1]) || is_string($errorInfo[1]))
            ? preg_replace('/[^0-9-]/', '', (string) $errorInfo[1])
            : null;
        $message = isset($errorInfo[2]) && is_string($errorInfo[2]) ? $errorInfo[2] : $exception->getMessage();
        $message = self::sanitize($message, $statement);

        $parts = [];
        if (is_string($sqlState) && $sqlState !== '') {
            $parts[] = "SQLSTATE $sqlState";
        }
        if (is_string($vendorCode) && $vendorCode !== '') {
            $parts[] = "MariaDB $vendorCode";
        }
        $prefix = $parts === [] ? 'MariaDB error' : implode(' / ', $parts);
        $diagnostic = self::limit($prefix.($message === '' ? '.' : ": $message"));

        return new self($diagnostic, $exception);
    }

    public function diagnostic(): string
    {
        return $this->safeDiagnostic;
    }

    private static function sanitize(string $message, string $statement): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '';
        if ($statement !== '') {
            $message = str_ireplace($statement, '[SQL redacted]', $message);
        }
        $patterns = [
            "/'[^']*'|\"[^\"]*\"|`[^`]*`/",
            '/mysql:[^\s]+/i',
            '/\b(?:password|passwd|pwd|username|user)\s*=\s*[^\s;]+/i',
            '/\b[A-Z]:[\\\\\/][^\s]+/i',
            '~(?<![A-Za-z0-9])/(?:[^/\s]+/)+[^/\s]+~',
        ];
        $message = preg_replace($patterns, '[redacted]', $message) ?? '';
        if (preg_match('//u', $message) !== 1) {
            $message = iconv('UTF-8', 'UTF-8//IGNORE', $message) ?: '';
        }

        return trim(preg_replace('/\s+/', ' ', $message) ?? '');
    }

    private static function limit(string $diagnostic): string
    {
        if (strlen($diagnostic) <= self::MAX_DIAGNOSTIC_BYTES) {
            return $diagnostic;
        }
        $diagnostic = substr($diagnostic, 0, self::MAX_DIAGNOSTIC_BYTES);
        while ($diagnostic !== '' && preg_match('//u', $diagnostic) !== 1) {
            $diagnostic = substr($diagnostic, 0, -1);
        }

        return rtrim($diagnostic);
    }
}
