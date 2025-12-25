<?php

declare(strict_types=1);

namespace Signalforge;

use Signalforge\Dotenv\Dotenv;
use Signalforge\Dotenv\DotenvException;

// Re-export the exception class in the Signalforge namespace
// to match the C extension's \Signalforge\DotenvException
if (!class_exists(\Signalforge\DotenvException::class)) {
    class_alias(DotenvException::class, \Signalforge\DotenvException::class);
}

/**
 * Load and parse a .env file.
 *
 * This function matches the C extension's API exactly:
 *   \Signalforge\dotenv(string $path = '.env', array $options = []): array
 *
 * @param string $path Path to the .env file
 * @param array{
 *     encrypted?: bool,
 *     key?: string,
 *     key_env?: string,
 *     override?: bool,
 *     export?: bool,
 *     export_server?: bool,
 *     format?: string,
 *     arrays?: bool,
 * } $options Configuration options:
 *   - encrypted: true = expect encrypted file (auto-detected by default)
 *   - key: Encryption passphrase
 *   - key_env: Environment variable containing the key
 *   - override: Override existing env vars (default: false)
 *   - export: Export to getenv()/$_ENV (default: true)
 *   - export_server: Also export to $_SERVER (default: false)
 *   - format: Value format: 'auto', 'plain', 'json' (default: 'auto')
 *   - arrays: Parse JSON arrays/objects (default: true)
 *
 * @return array<string, mixed> Parsed key-value pairs
 * @throws \Signalforge\DotenvException on error
 */
function dotenv(string $path = '.env', array $options = []): array
{
    return Dotenv::load($path, $options);
}
