<?php

declare(strict_types=1);

namespace Signalforge\Dotenv;

/**
 * Single-pass state machine parser for .env files.
 *
 * Supports:
 * - Standard KEY=value format
 * - Quoted values (single, double, backtick)
 * - Multiline values in double quotes/backticks
 * - Comments (# at line start or after whitespace)
 * - Escape sequences in double-quoted strings
 *
 * This is a direct port of the C extension's parser.c
 */
final class Parser
{
    /** Parser state constants - match C enum exactly */
    private const STATE_LINE_START = 0;
    private const STATE_KEY = 1;
    private const STATE_AFTER_KEY = 2;
    private const STATE_BEFORE_VALUE = 3;
    private const STATE_VALUE_UNQUOTED = 4;
    private const STATE_VALUE_SINGLE_QUOTED = 5;
    private const STATE_VALUE_DOUBLE_QUOTED = 6;
    private const STATE_VALUE_BACKTICK = 7;
    private const STATE_ESCAPE = 8;
    private const STATE_COMMENT = 9;
    private const STATE_LINE_END = 10;

    /** Lookup table for key start characters (A-Z, a-z, _) */
    private const KEY_START_CHARS = [
        '_' => true,
        'A' => true, 'B' => true, 'C' => true, 'D' => true, 'E' => true, 'F' => true,
        'G' => true, 'H' => true, 'I' => true, 'J' => true, 'K' => true, 'L' => true,
        'M' => true, 'N' => true, 'O' => true, 'P' => true, 'Q' => true, 'R' => true,
        'S' => true, 'T' => true, 'U' => true, 'V' => true, 'W' => true, 'X' => true,
        'Y' => true, 'Z' => true,
        'a' => true, 'b' => true, 'c' => true, 'd' => true, 'e' => true, 'f' => true,
        'g' => true, 'h' => true, 'i' => true, 'j' => true, 'k' => true, 'l' => true,
        'm' => true, 'n' => true, 'o' => true, 'p' => true, 'q' => true, 'r' => true,
        's' => true, 't' => true, 'u' => true, 'v' => true, 'w' => true, 'x' => true,
        'y' => true, 'z' => true,
    ];

    /** Lookup table for key characters (A-Z, a-z, 0-9, _) */
    private const KEY_CHARS = [
        '_' => true,
        '0' => true, '1' => true, '2' => true, '3' => true, '4' => true,
        '5' => true, '6' => true, '7' => true, '8' => true, '9' => true,
        'A' => true, 'B' => true, 'C' => true, 'D' => true, 'E' => true, 'F' => true,
        'G' => true, 'H' => true, 'I' => true, 'J' => true, 'K' => true, 'L' => true,
        'M' => true, 'N' => true, 'O' => true, 'P' => true, 'Q' => true, 'R' => true,
        'S' => true, 'T' => true, 'U' => true, 'V' => true, 'W' => true, 'X' => true,
        'Y' => true, 'Z' => true,
        'a' => true, 'b' => true, 'c' => true, 'd' => true, 'e' => true, 'f' => true,
        'g' => true, 'h' => true, 'i' => true, 'j' => true, 'k' => true, 'l' => true,
        'm' => true, 'n' => true, 'o' => true, 'p' => true, 'q' => true, 'r' => true,
        's' => true, 't' => true, 'u' => true, 'v' => true, 'w' => true, 'x' => true,
        'y' => true, 'z' => true,
    ];

    private string $input;
    private int $inputLen;
    private int $pos = 0;
    private int $line = 1;
    private int $column = 1;

    /** @var array<int, string> Key buffer as array for faster append */
    private array $keyBuf = [];

    /** @var array<int, string> Value buffer as array for faster append */
    private array $valueBuf = [];

    private int $state = self::STATE_LINE_START;

    /** @var array<string, string> */
    private array $result = [];

    public function __construct(string $input)
    {
        $this->input = $input;
        $this->inputLen = strlen($input);
    }

    /**
     * Parse the input and return key-value pairs.
     *
     * @return array<string, string>
     * @throws DotenvException on parse error
     */
    public function parse(): array
    {
        // Cache frequently accessed properties for speed
        $input = $this->input;
        $inputLen = $this->inputLen;
        $pos = 0;
        $line = 1;
        $column = 1;
        $state = self::STATE_LINE_START;
        $keyBuf = [];
        $valueBuf = [];

        while ($pos < $inputLen) {
            $c = $input[$pos];

            switch ($state) {
                case self::STATE_LINE_START:
                    if ($c === ' ' || $c === "\t") {
                        $pos++;
                        $column++;
                    } elseif ($c === '#') {
                        $state = self::STATE_COMMENT;
                        $pos++;
                        $column++;
                    } elseif ($c === "\n") {
                        $pos++;
                        $line++;
                        $column = 1;
                    } elseif ($c === "\r") {
                        $pos++;
                        $column++;
                    } elseif (isset(self::KEY_START_CHARS[$c])) {
                        $state = self::STATE_KEY;
                        $keyBuf[] = $c;
                        $pos++;
                        $column++;
                    } else {
                        throw DotenvException::parseError($line, $column, 'Invalid character at start of line');
                    }
                    break;

                case self::STATE_KEY:
                    if (isset(self::KEY_CHARS[$c])) {
                        $keyBuf[] = $c;
                        $pos++;
                        $column++;
                    } elseif ($c === '=' || $c === ' ' || $c === "\t") {
                        $state = self::STATE_AFTER_KEY;
                    } elseif ($c === "\n" || $c === "\r") {
                        // Key without value
                        if ($keyBuf !== []) {
                            $this->result[implode('', $keyBuf)] = '';
                            $keyBuf = [];
                        }
                        $state = self::STATE_LINE_START;
                        if ($c === "\n") {
                            $line++;
                            $column = 1;
                        } else {
                            $column++;
                        }
                        $pos++;
                    } else {
                        throw DotenvException::parseError($line, $column, 'Invalid character in key name');
                    }
                    break;

                case self::STATE_AFTER_KEY:
                    if ($c === ' ' || $c === "\t") {
                        $pos++;
                        $column++;
                    } elseif ($c === '=') {
                        $state = self::STATE_BEFORE_VALUE;
                        $pos++;
                        $column++;
                    } else {
                        throw DotenvException::parseError($line, $column, "Expected '=' after key");
                    }
                    break;

                case self::STATE_BEFORE_VALUE:
                    if ($c === ' ' || $c === "\t") {
                        $pos++;
                        $column++;
                    } elseif ($c === '"') {
                        $state = self::STATE_VALUE_DOUBLE_QUOTED;
                        $pos++;
                        $column++;
                    } elseif ($c === "'") {
                        $state = self::STATE_VALUE_SINGLE_QUOTED;
                        $pos++;
                        $column++;
                    } elseif ($c === '`') {
                        $state = self::STATE_VALUE_BACKTICK;
                        $pos++;
                        $column++;
                    } elseif ($c === "\n" || $c === "\r") {
                        // Empty value
                        if ($keyBuf !== []) {
                            $this->result[implode('', $keyBuf)] = '';
                            $keyBuf = [];
                        }
                        $state = self::STATE_LINE_START;
                        if ($c === "\n") {
                            $line++;
                            $column = 1;
                        } else {
                            $column++;
                        }
                        $pos++;
                    } elseif ($c === '#') {
                        // Empty value with comment
                        if ($keyBuf !== []) {
                            $this->result[implode('', $keyBuf)] = '';
                            $keyBuf = [];
                        }
                        $state = self::STATE_COMMENT;
                        $pos++;
                        $column++;
                    } else {
                        $state = self::STATE_VALUE_UNQUOTED;
                        $valueBuf[] = $c;
                        $pos++;
                        $column++;
                    }
                    break;

                case self::STATE_VALUE_UNQUOTED:
                    if ($c === "\n" || $c === "\r") {
                        // Trim trailing whitespace
                        $val = rtrim(implode('', $valueBuf), " \t");
                        if ($keyBuf !== []) {
                            $this->result[implode('', $keyBuf)] = $val;
                            $keyBuf = [];
                        }
                        $valueBuf = [];
                        $state = self::STATE_LINE_START;
                        if ($c === "\n") {
                            $line++;
                            $column = 1;
                        } else {
                            $column++;
                        }
                        $pos++;
                    } elseif ($c === '#') {
                        // Check for inline comment
                        $val = implode('', $valueBuf);
                        if ($val !== '' && ($val[-1] === ' ' || $val[-1] === "\t")) {
                            $val = rtrim($val, " \t");
                            if ($keyBuf !== []) {
                                $this->result[implode('', $keyBuf)] = $val;
                                $keyBuf = [];
                            }
                            $valueBuf = [];
                            $state = self::STATE_COMMENT;
                            $pos++;
                            $column++;
                        } else {
                            $valueBuf[] = $c;
                            $pos++;
                            $column++;
                        }
                    } else {
                        $valueBuf[] = $c;
                        $pos++;
                        $column++;
                    }
                    break;

                case self::STATE_VALUE_SINGLE_QUOTED:
                    if ($c === "'") {
                        if ($keyBuf !== []) {
                            $this->result[implode('', $keyBuf)] = implode('', $valueBuf);
                            $keyBuf = [];
                        }
                        $valueBuf = [];
                        $state = self::STATE_LINE_END;
                        $pos++;
                        $column++;
                    } elseif ($c === '\\' && $pos + 1 < $inputLen && $input[$pos + 1] === "'") {
                        $valueBuf[] = "'";
                        $pos += 2;
                        $column += 2;
                    } else {
                        $valueBuf[] = $c;
                        if ($c === "\n") {
                            $line++;
                            $column = 1;
                        } else {
                            $column++;
                        }
                        $pos++;
                    }
                    break;

                case self::STATE_VALUE_DOUBLE_QUOTED:
                    if ($c === '"') {
                        if ($keyBuf !== []) {
                            $this->result[implode('', $keyBuf)] = implode('', $valueBuf);
                            $keyBuf = [];
                        }
                        $valueBuf = [];
                        $state = self::STATE_LINE_END;
                        $pos++;
                        $column++;
                    } elseif ($c === '\\' && $pos + 1 < $inputLen) {
                        $pos++;
                        $column++;
                        $escaped = $input[$pos];
                        $valueBuf[] = match ($escaped) {
                            'n' => "\n",
                            'r' => "\r",
                            't' => "\t",
                            '\\' => '\\',
                            '"' => '"',
                            "'" => "'",
                            '$' => '$',
                            '`' => '`',
                            default => $escaped,
                        };
                        $pos++;
                        $column++;
                    } else {
                        $valueBuf[] = $c;
                        if ($c === "\n") {
                            $line++;
                            $column = 1;
                        } else {
                            $column++;
                        }
                        $pos++;
                    }
                    break;

                case self::STATE_VALUE_BACKTICK:
                    if ($c === '`') {
                        if ($keyBuf !== []) {
                            $this->result[implode('', $keyBuf)] = implode('', $valueBuf);
                            $keyBuf = [];
                        }
                        $valueBuf = [];
                        $state = self::STATE_LINE_END;
                        $pos++;
                        $column++;
                    } elseif ($c === '\\' && $pos + 1 < $inputLen) {
                        $pos++;
                        $column++;
                        $escaped = $input[$pos];
                        $valueBuf[] = match ($escaped) {
                            'n' => "\n",
                            'r' => "\r",
                            't' => "\t",
                            '\\' => '\\',
                            '"' => '"',
                            "'" => "'",
                            '$' => '$',
                            '`' => '`',
                            default => $escaped,
                        };
                        $pos++;
                        $column++;
                    } else {
                        $valueBuf[] = $c;
                        if ($c === "\n") {
                            $line++;
                            $column = 1;
                        } else {
                            $column++;
                        }
                        $pos++;
                    }
                    break;

                case self::STATE_COMMENT:
                    if ($c === "\n") {
                        $state = self::STATE_LINE_START;
                        $line++;
                        $column = 1;
                    } else {
                        $column++;
                    }
                    $pos++;
                    break;

                case self::STATE_LINE_END:
                    if ($c === ' ' || $c === "\t") {
                        $pos++;
                        $column++;
                    } elseif ($c === '#') {
                        $state = self::STATE_COMMENT;
                        $pos++;
                        $column++;
                    } elseif ($c === "\n" || $c === "\r") {
                        $state = self::STATE_LINE_START;
                        if ($c === "\n") {
                            $line++;
                            $column = 1;
                        } else {
                            $column++;
                        }
                        $pos++;
                    } else {
                        throw DotenvException::parseError($line, $column, 'Unexpected character after quoted value');
                    }
                    break;
            }
        }

        // Handle end of input
        if ($state === self::STATE_KEY || $state === self::STATE_VALUE_UNQUOTED ||
            $state === self::STATE_BEFORE_VALUE || $state === self::STATE_AFTER_KEY) {
            if ($keyBuf !== []) {
                $val = ($state === self::STATE_VALUE_UNQUOTED) ? implode('', $valueBuf) : '';
                $this->result[implode('', $keyBuf)] = $val;
            }
        } elseif ($state === self::STATE_VALUE_SINGLE_QUOTED ||
                  $state === self::STATE_VALUE_DOUBLE_QUOTED ||
                  $state === self::STATE_VALUE_BACKTICK) {
            throw DotenvException::parseError($line, $column, 'Unterminated quoted string at end of file');
        }

        return $this->result;
    }
}
