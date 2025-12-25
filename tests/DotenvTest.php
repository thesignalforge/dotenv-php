<?php

declare(strict_types=1);

namespace Signalforge\Dotenv\Tests;

use PHPUnit\Framework\TestCase;
use Signalforge\Dotenv\DotenvException;

use function Signalforge\dotenv;

/**
 * Tests ported from the C extension's .phpt test files.
 */
final class DotenvTest extends TestCase
{
    private string $tmpDir;
    private array $createdFiles = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->createdFiles = [];
    }

    private function createEnvFile(string $content): string
    {
        $path = $this->tmpDir . '/test_' . uniqid() . '.env';
        file_put_contents($path, $content);
        $this->createdFiles[] = $path;
        return $path;
    }

    /**
     * Test 001: Basic .env parsing
     */
    public function testBasicParsing(): void
    {
        $content = <<<'ENV'
# Comment line
APP_NAME=MyApp
APP_ENV=production
DEBUG=false

# Quoted values
GREETING="Hello, World!"
SINGLE_QUOTED='literal value without dollar'

# Empty value
EMPTY_VAR=
ENV;

        $envFile = $this->createEnvFile($content);
        $result = dotenv($envFile, ['export' => false]);

        $this->assertSame('MyApp', $result['APP_NAME']);
        $this->assertSame('production', $result['APP_ENV']);
        $this->assertSame('false', $result['DEBUG']);
        $this->assertSame('Hello, World!', $result['GREETING']);
        $this->assertSame('literal value without dollar', $result['SINGLE_QUOTED']);
        $this->assertSame('', $result['EMPTY_VAR']);
    }

    /**
     * Test 002: Variable expansion in .env values
     */
    public function testVariableExpansion(): void
    {
        $content = <<<'ENV'
BASE_URL=https://example.com
API_URL=${BASE_URL}/api
DEFAULT_PORT=${UNDEFINED_VAR:-8080}
ALTERNATE=${BASE_URL:+found}
SIMPLE=$BASE_URL
ENV;

        $envFile = $this->createEnvFile($content);
        $result = dotenv($envFile, ['export' => false]);

        $this->assertSame('https://example.com', $result['BASE_URL']);
        $this->assertSame('https://example.com/api', $result['API_URL']);
        $this->assertSame('8080', $result['DEFAULT_PORT']);
        $this->assertSame('found', $result['ALTERNATE']);
        $this->assertSame('https://example.com', $result['SIMPLE']);
    }

    /**
     * Test 003: JSON value parsing
     */
    public function testJsonParsing(): void
    {
        $content = <<<'ENV'
JSON_ARRAY=["one", "two", "three"]
JSON_OBJECT={"key": "value", "number": 42}
PLAIN_STRING=not json
ENV;

        $envFile = $this->createEnvFile($content);
        $result = dotenv($envFile, ['export' => false, 'arrays' => true]);

        $this->assertIsArray($result['JSON_ARRAY']);
        $this->assertSame(['one', 'two', 'three'], $result['JSON_ARRAY']);
        $this->assertIsArray($result['JSON_OBJECT']);
        $this->assertSame('value', $result['JSON_OBJECT']['key']);
        $this->assertSame(42, $result['JSON_OBJECT']['number']);
        $this->assertIsString($result['PLAIN_STRING']);
    }

    /**
     * Test 004: Multiline values and escape sequences
     */
    public function testMultilineAndEscape(): void
    {
        $content = <<<'ENV'
MULTILINE="line1
line2
line3"
ESCAPED="tab:\there\nnewline"
QUOTES="say \"hello\""
ENV;

        $envFile = $this->createEnvFile($content);
        $result = dotenv($envFile, ['export' => false]);

        $this->assertStringContainsString("\n", $result['MULTILINE']);
        $this->assertStringContainsString("\t", $result['ESCAPED']);
        $this->assertStringContainsString("\n", $result['ESCAPED']);
        $this->assertStringContainsString('"', $result['QUOTES']);
    }

    /**
     * Test 005: Environment variable injection
     */
    public function testEnvInjection(): void
    {
        $uniqueKey = 'SIGNALFORGE_TEST_' . uniqid();
        $content = $uniqueKey . "=test_value\n";

        $envFile = $this->createEnvFile($content);
        $result = dotenv($envFile, ['export' => true]);

        // Check getenv
        $this->assertSame('test_value', getenv($uniqueKey));

        // Check $_ENV
        $this->assertArrayHasKey($uniqueKey, $_ENV);
        $this->assertSame('test_value', $_ENV[$uniqueKey]);
    }

    /**
     * Test 006: Override option
     */
    public function testOverrideOption(): void
    {
        $uniqueKey = 'SIGNALFORGE_OVERRIDE_TEST_' . uniqid();

        // Set initial value
        putenv($uniqueKey . '=original');
        $_ENV[$uniqueKey] = 'original';

        $content = $uniqueKey . "=new_value\n";
        $envFile = $this->createEnvFile($content);

        // Without override - should keep original
        dotenv($envFile, ['export' => true, 'override' => false]);
        $this->assertSame('original', getenv($uniqueKey));

        // With override - should use new value
        dotenv($envFile, ['export' => true, 'override' => true]);
        $this->assertSame('new_value', getenv($uniqueKey));
    }

    /**
     * Test 007: File not found exception
     */
    public function testFileNotFound(): void
    {
        $this->expectException(DotenvException::class);
        $this->expectExceptionCode(DotenvException::ERR_FILE_NOT_FOUND);
        $this->expectExceptionMessageMatches('/Failed to read file/');

        dotenv('/nonexistent/path/.env');
    }

    /**
     * Test: Inline comments
     */
    public function testInlineComments(): void
    {
        $content = <<<'ENV'
VALUE_WITH_COMMENT=hello # this is a comment
VALUE_WITHOUT_SPACE=hello#not_a_comment
ENV;

        $envFile = $this->createEnvFile($content);
        $result = dotenv($envFile, ['export' => false]);

        $this->assertSame('hello', $result['VALUE_WITH_COMMENT']);
        $this->assertSame('hello#not_a_comment', $result['VALUE_WITHOUT_SPACE']);
    }

    /**
     * Test: Backtick quoted strings
     */
    public function testBacktickQuotes(): void
    {
        $content = <<<'ENV'
BACKTICK=`multiline
value with
backticks`
ENV;

        $envFile = $this->createEnvFile($content);
        $result = dotenv($envFile, ['export' => false]);

        $this->assertStringContainsString("\n", $result['BACKTICK']);
    }

    /**
     * Test: JSON arrays disabled
     */
    public function testJsonArraysDisabled(): void
    {
        $content = <<<'ENV'
JSON_ARRAY=["one", "two", "three"]
ENV;

        $envFile = $this->createEnvFile($content);
        $result = dotenv($envFile, ['export' => false, 'arrays' => false]);

        // Should remain as string when arrays parsing is disabled
        $this->assertIsString($result['JSON_ARRAY']);
        $this->assertSame('["one", "two", "three"]', $result['JSON_ARRAY']);
    }

    /**
     * Test: Self-referential variable expansion
     */
    public function testSelfReferentialExpansion(): void
    {
        $content = <<<'ENV'
A=first
B=${A}_second
C=${B}_third
ENV;

        $envFile = $this->createEnvFile($content);
        $result = dotenv($envFile, ['export' => false]);

        $this->assertSame('first', $result['A']);
        $this->assertSame('first_second', $result['B']);
        $this->assertSame('first_second_third', $result['C']);
    }

    /**
     * Test: Export to $_SERVER
     */
    public function testExportServer(): void
    {
        $uniqueKey = 'SIGNALFORGE_SERVER_TEST_' . uniqid();
        $content = $uniqueKey . "=server_value\n";

        $envFile = $this->createEnvFile($content);
        dotenv($envFile, ['export' => true, 'export_server' => true]);

        $this->assertArrayHasKey($uniqueKey, $_SERVER);
        $this->assertSame('server_value', $_SERVER[$uniqueKey]);
    }

    /**
     * Test: No export mode
     */
    public function testNoExport(): void
    {
        $uniqueKey = 'SIGNALFORGE_NOEXPORT_TEST_' . uniqid();
        $content = $uniqueKey . "=no_export_value\n";

        // Clear any existing value
        putenv($uniqueKey);
        unset($_ENV[$uniqueKey]);

        $envFile = $this->createEnvFile($content);
        $result = dotenv($envFile, ['export' => false]);

        // Should be in result but not in environment
        $this->assertSame('no_export_value', $result[$uniqueKey]);
        $this->assertFalse(getenv($uniqueKey));
    }

    /**
     * Test: Parse error on unterminated string
     */
    public function testUnterminatedString(): void
    {
        $content = 'KEY="unterminated';

        $envFile = $this->createEnvFile($content);

        $this->expectException(DotenvException::class);
        $this->expectExceptionCode(DotenvException::ERR_PARSE);
        $this->expectExceptionMessageMatches('/Unterminated/');

        dotenv($envFile, ['export' => false]);
    }

    /**
     * Test: Invalid key character
     */
    public function testInvalidKeyCharacter(): void
    {
        $content = '123INVALID=value';

        $envFile = $this->createEnvFile($content);

        $this->expectException(DotenvException::class);
        $this->expectExceptionCode(DotenvException::ERR_PARSE);

        dotenv($envFile, ['export' => false]);
    }
}
