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

    private string $input;
    private int $inputLen;
    private int $pos = 0;
    private int $line = 1;
    private int $column = 1;

    private string $currentKey = '';
    private string $currentValue = '';

    private int $state = self::STATE_LINE_START;
    private string $quoteChar = '';

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
        while ($this->pos < $this->inputLen) {
            $c = $this->peek();

            switch ($this->state) {
                case self::STATE_LINE_START:
                    $this->handleLineStart($c);
                    break;

                case self::STATE_KEY:
                    $this->handleKey($c);
                    break;

                case self::STATE_AFTER_KEY:
                    $this->handleAfterKey($c);
                    break;

                case self::STATE_BEFORE_VALUE:
                    $this->handleBeforeValue($c);
                    break;

                case self::STATE_VALUE_UNQUOTED:
                    $this->handleValueUnquoted($c);
                    break;

                case self::STATE_VALUE_SINGLE_QUOTED:
                    $this->handleValueSingleQuoted($c);
                    break;

                case self::STATE_VALUE_DOUBLE_QUOTED:
                    $this->handleValueDoubleQuoted($c);
                    break;

                case self::STATE_VALUE_BACKTICK:
                    $this->handleValueBacktick($c);
                    break;

                case self::STATE_COMMENT:
                    $this->handleComment($c);
                    break;

                case self::STATE_LINE_END:
                    $this->handleLineEnd($c);
                    break;

                default:
                    throw DotenvException::parseError(
                        $this->line,
                        $this->column,
                        'Internal parser error: invalid state'
                    );
            }
        }

        // Handle end of input
        $this->handleEndOfInput();

        return $this->result;
    }

    private function peek(): string
    {
        if ($this->pos >= $this->inputLen) {
            return "\0";
        }
        return $this->input[$this->pos];
    }

    private function advance(): void
    {
        if ($this->pos < $this->inputLen) {
            $c = $this->input[$this->pos];
            $this->pos++;
            if ($c === "\n") {
                $this->line++;
                $this->column = 1;
            } else {
                $this->column++;
            }
        }
    }

    private function consume(): string
    {
        $c = $this->peek();
        $this->advance();
        return $c;
    }

    private function isKeyStartChar(string $c): bool
    {
        return ($c >= 'A' && $c <= 'Z') ||
               ($c >= 'a' && $c <= 'z') ||
               $c === '_';
    }

    private function isKeyChar(string $c): bool
    {
        return $this->isKeyStartChar($c) ||
               ($c >= '0' && $c <= '9');
    }

    private function isWhitespace(string $c): bool
    {
        return $c === ' ' || $c === "\t";
    }

    private function isNewline(string $c): bool
    {
        return $c === "\n" || $c === "\r";
    }

    private function storeValue(): void
    {
        if ($this->currentKey === '') {
            return;
        }

        $this->result[$this->currentKey] = $this->currentValue;
        $this->currentKey = '';
        $this->currentValue = '';
    }

    private function processEscape(): string
    {
        $c = $this->consume();
        return match ($c) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            '\\' => '\\',
            '"' => '"',
            "'" => "'",
            '$' => '$',
            '`' => '`',
            default => $c, // Unknown escape, keep as-is
        };
    }

    private function handleLineStart(string $c): void
    {
        if ($this->isWhitespace($c)) {
            $this->advance();
        } elseif ($c === '#') {
            $this->state = self::STATE_COMMENT;
            $this->advance();
        } elseif ($this->isNewline($c)) {
            $this->advance();
        } elseif ($this->isKeyStartChar($c)) {
            $this->state = self::STATE_KEY;
            $this->currentKey .= $c;
            $this->advance();
        } else {
            throw DotenvException::parseError(
                $this->line,
                $this->column,
                'Invalid character at start of line'
            );
        }
    }

    private function handleKey(string $c): void
    {
        if ($this->isKeyChar($c)) {
            $this->currentKey .= $c;
            $this->advance();
        } elseif ($c === '=' || $this->isWhitespace($c)) {
            $this->state = self::STATE_AFTER_KEY;
        } elseif ($this->isNewline($c)) {
            // Key without value
            $this->storeValue();
            $this->state = self::STATE_LINE_START;
            $this->advance();
        } else {
            throw DotenvException::parseError(
                $this->line,
                $this->column,
                'Invalid character in key name'
            );
        }
    }

    private function handleAfterKey(string $c): void
    {
        if ($this->isWhitespace($c)) {
            $this->advance();
        } elseif ($c === '=') {
            $this->state = self::STATE_BEFORE_VALUE;
            $this->advance();
        } else {
            throw DotenvException::parseError(
                $this->line,
                $this->column,
                "Expected '=' after key"
            );
        }
    }

    private function handleBeforeValue(string $c): void
    {
        if ($this->isWhitespace($c)) {
            $this->advance();
        } elseif ($c === '"') {
            $this->state = self::STATE_VALUE_DOUBLE_QUOTED;
            $this->quoteChar = '"';
            $this->advance();
        } elseif ($c === "'") {
            $this->state = self::STATE_VALUE_SINGLE_QUOTED;
            $this->quoteChar = "'";
            $this->advance();
        } elseif ($c === '`') {
            $this->state = self::STATE_VALUE_BACKTICK;
            $this->quoteChar = '`';
            $this->advance();
        } elseif ($this->isNewline($c) || $c === "\0") {
            // Empty value
            $this->storeValue();
            $this->state = self::STATE_LINE_START;
            if ($c !== "\0") {
                $this->advance();
            }
        } elseif ($c === '#') {
            // Empty value with comment
            $this->storeValue();
            $this->state = self::STATE_COMMENT;
            $this->advance();
        } else {
            $this->state = self::STATE_VALUE_UNQUOTED;
            $this->currentValue .= $c;
            $this->advance();
        }
    }

    private function handleValueUnquoted(string $c): void
    {
        if ($this->isNewline($c) || $c === "\0") {
            // Trim trailing whitespace from unquoted value
            $this->currentValue = rtrim($this->currentValue, " \t");
            $this->storeValue();
            $this->state = self::STATE_LINE_START;
            if ($c !== "\0") {
                $this->advance();
            }
        } elseif ($c === '#') {
            // Check for inline comment (preceded by whitespace)
            if ($this->currentValue !== '' && $this->isWhitespace($this->currentValue[-1])) {
                // Trim trailing whitespace and treat as comment
                $this->currentValue = rtrim($this->currentValue, " \t");
                $this->storeValue();
                $this->state = self::STATE_COMMENT;
                $this->advance();
            } else {
                // # not preceded by whitespace, part of value
                $this->currentValue .= $c;
                $this->advance();
            }
        } else {
            $this->currentValue .= $c;
            $this->advance();
        }
    }

    private function handleValueSingleQuoted(string $c): void
    {
        if ($c === "'") {
            $this->storeValue();
            $this->state = self::STATE_LINE_END;
            $this->advance();
        } elseif ($c === '\\') {
            // Check for escaped quote
            if ($this->pos + 1 < $this->inputLen && $this->input[$this->pos + 1] === "'") {
                $this->advance();
                $this->currentValue .= "'";
                $this->advance();
            } else {
                $this->currentValue .= $c;
                $this->advance();
            }
        } elseif ($c === "\0") {
            throw DotenvException::parseError(
                $this->line,
                $this->column,
                'Unterminated single-quoted string'
            );
        } else {
            $this->currentValue .= $c;
            $this->advance();
        }
    }

    private function handleValueDoubleQuoted(string $c): void
    {
        if ($c === '"') {
            $this->storeValue();
            $this->state = self::STATE_LINE_END;
            $this->advance();
        } elseif ($c === '\\') {
            $this->advance();
            if ($this->pos < $this->inputLen) {
                $escaped = $this->processEscape();
                $this->currentValue .= $escaped;
            }
        } elseif ($c === "\0") {
            throw DotenvException::parseError(
                $this->line,
                $this->column,
                'Unterminated double-quoted string'
            );
        } else {
            $this->currentValue .= $c;
            $this->advance();
        }
    }

    private function handleValueBacktick(string $c): void
    {
        if ($c === '`') {
            $this->storeValue();
            $this->state = self::STATE_LINE_END;
            $this->advance();
        } elseif ($c === '\\') {
            $this->advance();
            if ($this->pos < $this->inputLen) {
                $escaped = $this->processEscape();
                $this->currentValue .= $escaped;
            }
        } elseif ($c === "\0") {
            throw DotenvException::parseError(
                $this->line,
                $this->column,
                'Unterminated backtick string'
            );
        } else {
            $this->currentValue .= $c;
            $this->advance();
        }
    }

    private function handleComment(string $c): void
    {
        if ($this->isNewline($c)) {
            $this->state = self::STATE_LINE_START;
            $this->advance();
        } else {
            $this->advance();
        }
    }

    private function handleLineEnd(string $c): void
    {
        if ($this->isWhitespace($c)) {
            $this->advance();
        } elseif ($c === '#') {
            $this->state = self::STATE_COMMENT;
            $this->advance();
        } elseif ($this->isNewline($c) || $c === "\0") {
            $this->state = self::STATE_LINE_START;
            if ($c !== "\0") {
                $this->advance();
            }
        } else {
            throw DotenvException::parseError(
                $this->line,
                $this->column,
                'Unexpected character after quoted value'
            );
        }
    }

    private function handleEndOfInput(): void
    {
        switch ($this->state) {
            case self::STATE_KEY:
            case self::STATE_VALUE_UNQUOTED:
            case self::STATE_BEFORE_VALUE:
            case self::STATE_AFTER_KEY:
                $this->storeValue();
                break;

            case self::STATE_VALUE_SINGLE_QUOTED:
            case self::STATE_VALUE_DOUBLE_QUOTED:
            case self::STATE_VALUE_BACKTICK:
                throw DotenvException::parseError(
                    $this->line,
                    $this->column,
                    'Unterminated quoted string at end of file'
                );
        }
    }
}
