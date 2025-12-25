<?php

declare(strict_types=1);

namespace Signalforge\Dotenv;

/**
 * Encryption/decryption using libsodium.
 *
 * Uses the same binary format as the C extension:
 * - Magic: "SFDOTENV" (8 bytes)
 * - Version: 0x01 (1 byte)
 * - Reserved: 0x00 0x00 0x00 (3 bytes)
 * - Salt: 16 bytes (crypto_pwhash_SALTBYTES)
 * - Nonce: 24 bytes (SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
 * - Ciphertext: variable length (includes MAC)
 *
 * Key derivation: Argon2id (moderate settings)
 * Encryption: XSalsa20-Poly1305 (crypto_secretbox)
 */
final class Crypto
{
    private const MAGIC = 'SFDOTENV';
    private const MAGIC_LEN = 8;
    private const VERSION_1 = 0x01;
    private const RESERVED_LEN = 3;

    // Header size: 8 + 1 + 3 + 16 + 24 = 52 bytes
    private const HEADER_SIZE = self::MAGIC_LEN + 1 + self::RESERVED_LEN +
                                SODIUM_CRYPTO_PWHASH_SALTBYTES +
                                SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    // Minimum ciphertext size: header + MAC (16 bytes)
    private const MIN_SIZE = self::HEADER_SIZE + SODIUM_CRYPTO_SECRETBOX_MACBYTES;

    /**
     * Check if data appears to be encrypted with our format.
     */
    public static function isEncrypted(string $data): bool
    {
        if (strlen($data) < self::HEADER_SIZE) {
            return false;
        }

        return substr($data, 0, self::MAGIC_LEN) === self::MAGIC;
    }

    /**
     * Encrypt plaintext using a passphrase.
     *
     * @param string $plaintext The data to encrypt
     * @param string $passphrase The encryption passphrase
     * @return string The encrypted data with header
     * @throws DotenvException on encryption failure
     */
    public static function encrypt(string $plaintext, string $passphrase): string
    {
        if ($passphrase === '') {
            throw DotenvException::keyInvalid('passphrase cannot be empty');
        }

        // Generate random salt and nonce
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        // Derive key from passphrase using Argon2id
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        // Encrypt with secretbox
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $key);

        // Secure cleanup of key
        sodium_memzero($key);

        // Build header
        $header = self::MAGIC;
        $header .= chr(self::VERSION_1);
        $header .= str_repeat("\x00", self::RESERVED_LEN);
        $header .= $salt;
        $header .= $nonce;

        return $header . $ciphertext;
    }

    /**
     * Decrypt ciphertext using a passphrase.
     *
     * @param string $ciphertext The encrypted data with header
     * @param string $passphrase The decryption passphrase
     * @return string The decrypted plaintext
     * @throws DotenvException on decryption failure
     */
    public static function decrypt(string $ciphertext, string $passphrase): string
    {
        if ($passphrase === '') {
            throw DotenvException::keyInvalid('passphrase cannot be empty');
        }

        if (strlen($ciphertext) < self::MIN_SIZE) {
            throw DotenvException::decryptionFailed('Invalid encrypted data format');
        }

        // Parse header
        $offset = 0;

        $magic = substr($ciphertext, $offset, self::MAGIC_LEN);
        $offset += self::MAGIC_LEN;

        if ($magic !== self::MAGIC) {
            throw DotenvException::decryptionFailed('Data is not encrypted');
        }

        $version = ord($ciphertext[$offset]);
        $offset += 1;

        if ($version !== self::VERSION_1) {
            throw DotenvException::decryptionFailed('Unsupported encryption format version');
        }

        // Skip reserved bytes
        $offset += self::RESERVED_LEN;

        $salt = substr($ciphertext, $offset, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $offset += SODIUM_CRYPTO_PWHASH_SALTBYTES;

        $nonce = substr($ciphertext, $offset, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $offset += SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

        $encryptedData = substr($ciphertext, $offset);

        // Derive key from passphrase
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        // Decrypt
        $plaintext = sodium_crypto_secretbox_open($encryptedData, $nonce, $key);

        // Secure cleanup of key
        sodium_memzero($key);

        if ($plaintext === false) {
            throw DotenvException::decryptionFailed('wrong key or tampered data');
        }

        return $plaintext;
    }

    /**
     * Calculate required buffer size for encrypted data.
     */
    public static function ciphertextLength(int $plaintextLen): int
    {
        return self::HEADER_SIZE + $plaintextLen + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
    }

    /**
     * Calculate maximum plaintext size from ciphertext.
     */
    public static function plaintextMaxLength(int $ciphertextLen): int
    {
        if ($ciphertextLen < self::MIN_SIZE) {
            return 0;
        }
        return $ciphertextLen - self::HEADER_SIZE - SODIUM_CRYPTO_SECRETBOX_MACBYTES;
    }
}
