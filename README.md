# Signalforge Dotenv (Pure PHP)

Pure PHP implementation of the `signalforge_dotenv` C extension for loading, parsing, and decrypting `.env` files.

## What This Package Replaces

This package is a drop-in replacement for the `signalforge_dotenv` PHP C extension. It provides identical API and behavior, allowing you to use the same code whether the C extension is installed or not.

## API Parity Guarantees

- **Function signature**: `\Signalforge\dotenv(string $path = '.env', array $options = []): array`
- **Exception class**: `\Signalforge\DotenvException` with identical error codes
- **Options**: All options from the C extension are supported
- **Parsing behavior**: Identical state machine parser
- **Variable expansion**: Full support for `${VAR}`, `${VAR:-default}`, `${VAR:+alternate}`
- **JSON parsing**: Automatic parsing of JSON arrays and objects
- **Encryption**: Compatible binary format using libsodium (Argon2id + XSalsa20-Poly1305)

## Requirements

- PHP 8.4+
- ext-sodium (required for encryption/decryption)
- ext-json (required for JSON value parsing)

## Installation

```bash
composer require signalforge/dotenv
```

## Quick Start

```php
<?php
// Load .env from current directory
$env = \Signalforge\dotenv();

// Access values
echo $env['APP_NAME'];
echo getenv('APP_NAME');
echo $_ENV['APP_NAME'];
```

## Usage Examples

### Loading from Different Paths

```php
// Load from specific path
$env = \Signalforge\dotenv('/path/to/.env');

// Load with custom options
$env = \Signalforge\dotenv('.env.local', ['override' => true]);
```

### Variable Expansion

```php
// .env file:
// BASE_URL=https://api.example.com
// API_ENDPOINT=${BASE_URL}/v2
// LOG_LEVEL=${LOG_LEVEL:-info}
// DEBUG_MODE=${DEBUG:+enabled}

$env = \Signalforge\dotenv();

echo $env['API_ENDPOINT'];  // https://api.example.com/v2
echo $env['LOG_LEVEL'];     // "info" (default, since LOG_LEVEL wasn't set)
echo $env['DEBUG_MODE'];    // "" (empty, since DEBUG wasn't set)
```

### JSON Values

```php
// .env file:
// ALLOWED_HOSTS=["localhost", "127.0.0.1"]
// DB_CONFIG={"host": "localhost", "port": 5432}

$env = \Signalforge\dotenv('.env', ['arrays' => true]);

foreach ($env['ALLOWED_HOSTS'] as $host) {
    echo "Allowed: $host\n";
}

$port = $env['DB_CONFIG']['port'];
```

### Encrypted Files

```bash
# Set encryption key via environment
export SIGNALFORGE_DOTENV_KEY="your-secure-passphrase"
```

```php
// Auto-detect encryption and use key from environment
$env = \Signalforge\dotenv('.env.encrypted', [
    'encrypted' => true,
]);

// Or specify key source explicitly
$env = \Signalforge\dotenv('.env.encrypted', [
    'encrypted' => true,
    'key_env' => 'MY_CUSTOM_KEY_VAR',
]);
```

### Control Export Behavior

```php
// Export to getenv() and $_ENV (default)
$env = \Signalforge\dotenv('.env', ['export' => true]);

// Also export to $_SERVER
$env = \Signalforge\dotenv('.env', [
    'export' => true,
    'export_server' => true,
]);

// Parse only, don't modify environment
$env = \Signalforge\dotenv('.env', ['export' => false]);
```

### Override Existing Variables

```php
// By default, existing env vars are NOT overwritten
$env = \Signalforge\dotenv('.env');

// Force override of existing values
$env = \Signalforge\dotenv('.env', ['override' => true]);
```

## Options Reference

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `encrypted` | bool | auto | Whether to expect encrypted file |
| `key` | string | null | Encryption passphrase |
| `key_env` | string | null | Env var containing the key |
| `override` | bool | false | Override existing env vars |
| `export` | bool | true | Export to getenv()/$_ENV |
| `export_server` | bool | false | Also export to $_SERVER |
| `arrays` | bool | true | Parse JSON arrays/objects |

## Exception Codes

| Code | Constant | Description |
|------|----------|-------------|
| 1 | `ERR_FILE_NOT_FOUND` | File not found |
| 2 | `ERR_FILE_READ` | File read error |
| 3 | `ERR_PARSE` | Parse error |
| 4 | `ERR_DECRYPT` | Decryption error |
| 5 | `ERR_KEY_REQUIRED` | Key required but not provided |
| 6 | `ERR_KEY_INVALID` | Invalid key format |
| 8 | `ERR_JSON_PARSE` | JSON parse error |
| 9 | `ERR_CRYPTO_INIT` | Crypto initialization error |

## Performance Caveats vs C Extension

This pure PHP implementation prioritizes correctness over speed. Key differences:

| Aspect | C Extension | Pure PHP |
|--------|-------------|----------|
| Parser | Single-pass, zero-copy | String operations |
| Memory | Manual management | GC-managed |
| Encryption | libsodium C bindings | PHP sodium wrapper |
| Key zeroing | `sodium_memzero()` | Unset (not secure) |

**Recommendation**: Use the C extension in production for:
- High-throughput applications
- Security-critical key handling
- Memory-constrained environments

## When to Prefer the C Version

1. **Performance**: The C extension is significantly faster for large files
2. **Secure memory**: The C extension uses `sodium_memzero()` for secure key cleanup
3. **Resource usage**: Lower memory overhead per request
4. **Long-running processes**: Better for Swoole/RoadRunner workloads

## When This Package is Sufficient

1. **Development environments**: Where the C extension isn't installed
2. **Shared hosting**: Where you can't install PHP extensions
3. **Simple applications**: With small .env files and low throughput
4. **Portability**: When you need to run on any PHP installation

## Encryption Format Compatibility

This package uses the exact same binary format as the C extension:

```
+------------------+
| Magic (8 bytes)  |  "SFDOTENV"
+------------------+
| Version (1 byte) |  0x01
+------------------+
| Reserved (3 b)   |
+------------------+
| Salt (16 bytes)  |  For Argon2id KDF
+------------------+
| Nonce (24 bytes) |  For XSalsa20
+------------------+
| Ciphertext       |  Encrypted + Poly1305 MAC
+------------------+
```

Files encrypted with the C extension can be decrypted with this package and vice versa.

## Security Notes

### Limitation: Secure Memory Zeroing

PHP strings are immutable and garbage-collected. This means:

- Encryption keys cannot be securely zeroed from memory
- Decrypted content may persist in memory until GC runs
- For security-critical applications, use the C extension

### Best Practices

```php
// DON'T: Hardcode keys
$env = \Signalforge\dotenv('.env', ['key' => 'my-secret']);

// DO: Use environment variables
$env = \Signalforge\dotenv('.env', ['key_env' => 'DOTENV_KEY']);

// DON'T: Log decrypted values
error_log(print_r($env, true));

// DO: Only log non-sensitive info
error_log("Loaded " . count($env) . " environment variables");
```

## Testing

```bash
composer install
composer test
```

## License

MIT License - See LICENSE file
