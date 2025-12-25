<?php

declare(strict_types=1);

namespace Signalforge\Dotenv;

use Exception;

/**
 * Exception thrown by dotenv operations.
 *
 * Error codes match the C extension exactly:
 * - 1: File not found
 * - 2: File read error
 * - 3: Parse error
 * - 4: Decryption error
 * - 5: Key required but not provided
 * - 6: Invalid key format
 * - 7: Memory allocation error (not applicable in PHP)
 * - 8: JSON parse error
 * - 9: Crypto initialization error
 */
final class DotenvException extends Exception
{
    public const ERR_FILE_NOT_FOUND = 1;
    public const ERR_FILE_READ = 2;
    public const ERR_PARSE = 3;
    public const ERR_DECRYPT = 4;
    public const ERR_KEY_REQUIRED = 5;
    public const ERR_KEY_INVALID = 6;
    public const ERR_MEMORY = 7;
    public const ERR_JSON_PARSE = 8;
    public const ERR_CRYPTO_INIT = 9;

    public static function fileNotFound(string $path): self
    {
        return new self(
            sprintf('Failed to read file: %s', $path),
            self::ERR_FILE_NOT_FOUND
        );
    }

    public static function fileReadError(string $path): self
    {
        return new self(
            sprintf('Failed to read file: %s', $path),
            self::ERR_FILE_READ
        );
    }

    public static function parseError(int $line, int $column, string $message): self
    {
        return new self(
            sprintf('Parse error at line %d, column %d: %s', $line, $column, $message),
            self::ERR_PARSE
        );
    }

    public static function decryptionFailed(string $reason): self
    {
        return new self(
            sprintf('Decryption failed: %s', $reason),
            self::ERR_DECRYPT
        );
    }

    public static function keyRequired(): self
    {
        return new self(
            'Encryption key required but not provided',
            self::ERR_KEY_REQUIRED
        );
    }

    public static function keyInvalid(string $reason): self
    {
        return new self(
            sprintf('Invalid encryption key: %s', $reason),
            self::ERR_KEY_INVALID
        );
    }

    public static function jsonParseError(string $key): self
    {
        return new self(
            sprintf('Failed to parse JSON value for key: %s', $key),
            self::ERR_JSON_PARSE
        );
    }

    public static function cryptoInitError(): self
    {
        return new self(
            'Failed to initialize cryptography subsystem',
            self::ERR_CRYPTO_INIT
        );
    }
}
