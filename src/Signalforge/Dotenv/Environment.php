<?php

declare(strict_types=1);

namespace Signalforge\Dotenv;

/**
 * Handles injection of parsed values into the PHP environment.
 *
 * Targets:
 * - getenv() / putenv()
 * - $_ENV superglobal
 * - $_SERVER superglobal
 *
 * This is a direct port of env.c from the C extension.
 */
final class Environment
{
    /** Target flags - match C enum */
    public const TARGET_NONE = 0;
    public const TARGET_GETENV = 1;  // 1 << 0
    public const TARGET_ENV = 2;     // 1 << 1
    public const TARGET_SERVER = 4;  // 1 << 2
    public const TARGET_ALL = 7;     // TARGET_GETENV | TARGET_ENV | TARGET_SERVER

    /** Track putenv entries for cleanup */
    private static array $putenvEntries = [];

    /**
     * Validate an environment variable name.
     * Must match: [a-zA-Z_][a-zA-Z0-9_]*
     */
    public static function validateKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        // First character must be letter or underscore
        $c = $key[0];
        if (!(($c >= 'A' && $c <= 'Z') || ($c >= 'a' && $c <= 'z') || $c === '_')) {
            return false;
        }

        // Remaining characters can include digits
        $len = strlen($key);
        for ($i = 1; $i < $len; $i++) {
            $c = $key[$i];
            if (!(($c >= 'A' && $c <= 'Z') || ($c >= 'a' && $c <= 'z') ||
                  ($c >= '0' && $c <= '9') || $c === '_')) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an environment variable exists.
     */
    public static function exists(string $key): bool
    {
        // Check $_ENV
        if (isset($_ENV[$key])) {
            return true;
        }

        // Check getenv
        return getenv($key) !== false;
    }

    /**
     * Get an environment variable value.
     */
    public static function get(string $key): ?string
    {
        // Check $_ENV first
        if (isset($_ENV[$key]) && is_string($_ENV[$key])) {
            return $_ENV[$key];
        }

        // Fall back to getenv
        $value = getenv($key);
        if ($value !== false) {
            return $value;
        }

        return null;
    }

    /**
     * Get all environment variables.
     *
     * @return array<string, string>
     */
    public static function getAll(): array
    {
        $result = [];

        // Start with getenv (all process environment)
        $envVars = getenv();
        if (is_array($envVars)) {
            foreach ($envVars as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $result[$key] = $value;
                }
            }
        }

        // Override with $_ENV
        foreach ($_ENV as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Serialize a value to string for environment storage.
     *
     * @param mixed $value
     */
    public static function serializeValue(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_null($value) => '',
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
            is_object($value) && method_exists($value, '__toString') => (string) $value,
            default => '',
        };
    }

    /**
     * Set a single environment variable.
     *
     * @param string $key Variable name
     * @param string $value Variable value
     * @param int $targets Bitmask of targets (TARGET_GETENV, TARGET_ENV, TARGET_SERVER)
     * @param bool $override Whether to override existing values
     * @return bool True on success
     */
    public static function set(
        string $key,
        string $value,
        int $targets = self::TARGET_GETENV | self::TARGET_ENV,
        bool $override = false
    ): bool {
        if (!self::validateKey($key)) {
            return false;
        }

        // Check if we should skip (no override and exists)
        if (!$override && self::exists($key)) {
            return true; // Not an error, just skipped
        }

        // Inject into $_ENV
        if ($targets & self::TARGET_ENV) {
            $_ENV[$key] = $value;
        }

        // Inject into $_SERVER
        if ($targets & self::TARGET_SERVER) {
            $_SERVER[$key] = $value;
        }

        // Inject into process environment via putenv
        if ($targets & self::TARGET_GETENV) {
            $envStr = $key . '=' . $value;
            if (putenv($envStr)) {
                self::$putenvEntries[] = $key;
            }
        }

        return true;
    }

    /**
     * Set a zval-style value (handles arrays).
     *
     * @param string $key Variable name
     * @param mixed $value Variable value (string or array)
     * @param int $targets Bitmask of targets
     * @param bool $override Whether to override existing values
     * @return bool True on success
     */
    public static function setMixed(
        string $key,
        mixed $value,
        int $targets = self::TARGET_GETENV | self::TARGET_ENV,
        bool $override = false
    ): bool {
        if (!self::validateKey($key)) {
            return false;
        }

        if (!$override && self::exists($key)) {
            return true;
        }

        // For $_ENV and $_SERVER, we can store arrays directly
        if ($targets & self::TARGET_ENV) {
            $_ENV[$key] = $value;
        }

        if ($targets & self::TARGET_SERVER) {
            $_SERVER[$key] = $value;
        }

        // For getenv, we need to serialize to string
        if ($targets & self::TARGET_GETENV) {
            $serialized = self::serializeValue($value);
            $envStr = $key . '=' . $serialized;
            if (putenv($envStr)) {
                self::$putenvEntries[] = $key;
            }
        }

        return true;
    }

    /**
     * Set all values from an array.
     *
     * @param array<string, mixed> $values Key-value pairs
     * @param int $targets Bitmask of targets
     * @param bool $override Whether to override existing values
     * @return int Number of values successfully set
     */
    public static function setAll(
        array $values,
        int $targets = self::TARGET_GETENV | self::TARGET_ENV,
        bool $override = false
    ): int {
        $count = 0;

        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (self::setMixed($key, $value, $targets, $override)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get the list of keys set via putenv.
     * Useful for cleanup in long-running processes.
     *
     * @return array<string>
     */
    public static function getPutenvKeys(): array
    {
        return self::$putenvEntries;
    }

    /**
     * Clear tracked putenv entries.
     * Note: This doesn't actually unset the environment variables,
     * it just clears the tracking array.
     */
    public static function clearTracking(): void
    {
        self::$putenvEntries = [];
    }
}
