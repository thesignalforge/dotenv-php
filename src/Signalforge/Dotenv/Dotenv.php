<?php

declare(strict_types=1);

namespace Signalforge\Dotenv;

/**
 * Main dotenv loader class.
 *
 * Loads, parses, and optionally decrypts .env files.
 * Injects values into the PHP environment.
 *
 * This is the pure PHP equivalent of the signalforge_dotenv C extension.
 */
final class Dotenv
{
    /**
     * Load and parse a .env file.
     *
     * @param string $path Path to the .env file (default: ".env")
     * @param array{
     *     encrypted?: bool,
     *     key?: string,
     *     key_env?: string,
     *     override?: bool,
     *     export?: bool,
     *     export_server?: bool,
     *     format?: string,
     *     arrays?: bool,
     * } $options Configuration options
     * @return array<string, mixed> Parsed key-value pairs
     * @throws DotenvException on error
     */
    public static function load(string $path = '.env', array $options = []): array
    {
        // Parse options with defaults
        $encrypted = $options['encrypted'] ?? null;
        $key = $options['key'] ?? null;
        $keyEnv = $options['key_env'] ?? null;
        $override = $options['override'] ?? false;
        $export = $options['export'] ?? true;
        $exportServer = $options['export_server'] ?? false;
        $parseArrays = $options['arrays'] ?? true;

        // Auto-detect encryption if not explicitly set
        $autoDetect = ($encrypted === null);

        // Read file
        $content = self::readFile($path);

        // Check for encryption and decrypt if needed
        $isEncrypted = Crypto::isEncrypted($content);

        if ($isEncrypted || ($encrypted === true && !$autoDetect)) {
            $encryptionKey = self::getEncryptionKey($key, $keyEnv);
            if ($encryptionKey === null) {
                throw DotenvException::keyRequired();
            }

            $content = Crypto::decrypt($content, $encryptionKey);

            // Note: PHP strings are immutable, so we cannot securely zero the key.
            // This is a documented limitation of the pure PHP implementation.
            unset($encryptionKey);
        }

        // Parse .env content
        $parser = new Parser($content);
        $values = $parser->parse();

        // Post-process: variable expansion and JSON parsing
        $values = self::postProcessValues($values, $parseArrays);

        // Inject into environment
        if ($export) {
            $targets = Environment::TARGET_GETENV | Environment::TARGET_ENV;
            if ($exportServer) {
                $targets |= Environment::TARGET_SERVER;
            }
            Environment::setAll($values, $targets, $override);
        }

        return $values;
    }

    /**
     * Read file contents.
     *
     * @throws DotenvException if file cannot be read
     */
    private static function readFile(string $path): string
    {
        if (!file_exists($path)) {
            throw DotenvException::fileNotFound($path);
        }

        if (!is_file($path)) {
            throw DotenvException::fileNotFound($path);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw DotenvException::fileReadError($path);
        }

        return $content;
    }

    /**
     * Get encryption key from options or environment.
     */
    private static function getEncryptionKey(?string $key, ?string $keyEnv): ?string
    {
        // Direct key takes precedence
        if ($key !== null && $key !== '') {
            return $key;
        }

        // Try specified environment variable
        if ($keyEnv !== null && $keyEnv !== '') {
            $envKey = Environment::get($keyEnv);
            if ($envKey !== null) {
                return $envKey;
            }
        }

        // Try default SIGNALFORGE_DOTENV_KEY
        $envKey = Environment::get('SIGNALFORGE_DOTENV_KEY');
        if ($envKey !== null) {
            return $envKey;
        }

        // Try DOTENV_PRIVATE_KEY for dotenvx compatibility
        $envKey = Environment::get('DOTENV_PRIVATE_KEY');
        if ($envKey !== null) {
            return $envKey;
        }

        return null;
    }

    /**
     * Post-process parsed values: variable expansion and JSON parsing.
     *
     * @param array<string, string> $values Parsed raw values
     * @param bool $parseArrays Whether to parse JSON arrays/objects
     * @return array<string, mixed> Processed values
     */
    private static function postProcessValues(array $values, bool $parseArrays): array
    {
        // Build environment for expansion (existing + parsed)
        // Use array union to avoid loop overhead
        $env = $values + Environment::getAll();

        // Expand variables and parse JSON in single pass
        $result = [];

        foreach ($values as $key => $value) {
            // Expand variables
            $expanded = VariableExpander::expand($value, $env);

            // Update env for subsequent expansions
            $env[$key] = $expanded;

            // Try JSON parsing if enabled and value looks like JSON
            if ($parseArrays) {
                $first = $expanded[0] ?? '';
                if ($first === '[' || $first === '{') {
                    $jsonValue = json_decode($expanded, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonValue)) {
                        $result[$key] = $jsonValue;
                        continue;
                    }
                }
            }

            $result[$key] = $expanded;
        }

        return $result;
    }
}
