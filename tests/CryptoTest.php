<?php

declare(strict_types=1);

namespace Signalforge\Dotenv\Tests;

use PHPUnit\Framework\TestCase;
use Signalforge\Dotenv\Crypto;
use Signalforge\Dotenv\DotenvException;

/**
 * Tests for the Crypto class.
 */
final class CryptoTest extends TestCase
{
    public function testEncryptDecryptRoundtrip(): void
    {
        $plaintext = "APP_KEY=secret\nDB_PASS=password123";
        $passphrase = 'my-secure-passphrase';

        $encrypted = Crypto::encrypt($plaintext, $passphrase);
        $decrypted = Crypto::decrypt($encrypted, $passphrase);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testIsEncrypted(): void
    {
        $plaintext = "APP_KEY=secret";
        $passphrase = 'test-key';

        $encrypted = Crypto::encrypt($plaintext, $passphrase);

        $this->assertTrue(Crypto::isEncrypted($encrypted));
        $this->assertFalse(Crypto::isEncrypted($plaintext));
        $this->assertFalse(Crypto::isEncrypted('short'));
    }

    public function testWrongPassphraseThrows(): void
    {
        $plaintext = "APP_KEY=secret";
        $passphrase = 'correct-key';
        $wrongPassphrase = 'wrong-key';

        $encrypted = Crypto::encrypt($plaintext, $passphrase);

        $this->expectException(DotenvException::class);
        $this->expectExceptionCode(DotenvException::ERR_DECRYPT);

        Crypto::decrypt($encrypted, $wrongPassphrase);
    }

    public function testEmptyPassphraseThrows(): void
    {
        $this->expectException(DotenvException::class);
        $this->expectExceptionCode(DotenvException::ERR_KEY_INVALID);

        Crypto::encrypt('plaintext', '');
    }

    public function testCorruptedDataThrows(): void
    {
        $plaintext = "APP_KEY=secret";
        $passphrase = 'test-key';

        $encrypted = Crypto::encrypt($plaintext, $passphrase);

        // Corrupt the ciphertext (last byte)
        $corrupted = substr($encrypted, 0, -1) . chr(ord($encrypted[-1]) ^ 0xFF);

        $this->expectException(DotenvException::class);
        $this->expectExceptionCode(DotenvException::ERR_DECRYPT);

        Crypto::decrypt($corrupted, $passphrase);
    }

    public function testNotEncryptedDataThrows(): void
    {
        $this->expectException(DotenvException::class);
        $this->expectExceptionCode(DotenvException::ERR_DECRYPT);

        Crypto::decrypt('not encrypted data', 'key');
    }

    public function testCiphertextLengthCalculation(): void
    {
        $plaintext = "Hello, World!";
        $passphrase = 'test-key';

        $expectedLen = Crypto::ciphertextLength(strlen($plaintext));
        $encrypted = Crypto::encrypt($plaintext, $passphrase);

        $this->assertSame($expectedLen, strlen($encrypted));
    }

    public function testPlaintextMaxLengthCalculation(): void
    {
        $plaintext = "Hello, World!";
        $passphrase = 'test-key';

        $encrypted = Crypto::encrypt($plaintext, $passphrase);
        $maxLen = Crypto::plaintextMaxLength(strlen($encrypted));

        $this->assertSame(strlen($plaintext), $maxLen);
    }

    public function testLargeFile(): void
    {
        // Test with a larger file
        $lines = [];
        for ($i = 0; $i < 100; $i++) {
            $lines[] = "KEY_{$i}=value_{$i}";
        }
        $plaintext = implode("\n", $lines);
        $passphrase = 'large-file-test-key';

        $encrypted = Crypto::encrypt($plaintext, $passphrase);
        $decrypted = Crypto::decrypt($encrypted, $passphrase);

        $this->assertSame($plaintext, $decrypted);
    }

    public function testUnicodeContent(): void
    {
        $plaintext = "MESSAGE=Hello, 世界! 🌍\nEMOJI=🔐🔑";
        $passphrase = 'unicode-test-ключ';

        $encrypted = Crypto::encrypt($plaintext, $passphrase);
        $decrypted = Crypto::decrypt($encrypted, $passphrase);

        $this->assertSame($plaintext, $decrypted);
    }
}
