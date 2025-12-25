<?php

declare(strict_types=1);

namespace Signalforge\Dotenv;

/**
 * Expands shell-style variable references in strings.
 *
 * Supports:
 * - $VAR - Simple variable reference
 * - ${VAR} - Braced variable reference
 * - ${VAR:-default} - Use default if VAR is unset
 * - ${VAR:+alternate} - Use alternate if VAR is set and non-empty
 * - ${VAR-default} - Use default if VAR is unset (no colon = only if unset)
 *
 * This is a direct port of sf_expand_variables from the C extension.
 */
final class VariableExpander
{
    /**
     * Expand variables in a string.
     *
     * @param string $input The input string with variable references
     * @param array<string, string|array> $env Environment to look up variables
     * @return string The expanded string
     */
    public static function expand(string $input, array $env): string
    {
        $output = '';
        $len = strlen($input);
        $i = 0;

        while ($i < $len) {
            if ($input[$i] === '$' && $i + 1 < $len) {
                $hasBraces = ($input[$i + 1] === '{');

                if ($hasBraces) {
                    $start = $i + 2;
                    $end = $start;

                    // Find closing brace
                    while ($end < $len && $input[$end] !== '}') {
                        $end++;
                    }

                    if ($end >= $len) {
                        // No closing brace, copy literally
                        $output .= '$';
                        $i++;
                        continue;
                    }

                    // Parse the variable reference
                    $varRef = substr($input, $start, $end - $start);
                    $result = self::parseAndResolve($varRef, $env);
                    $output .= $result;
                    $i = $end + 1;
                } else {
                    // $VAR without braces
                    $start = $i + 1;
                    $end = $start;

                    while ($end < $len && self::isKeyChar($input[$end])) {
                        $end++;
                    }

                    if ($end === $start) {
                        // Just a $ sign
                        $output .= '$';
                        $i++;
                        continue;
                    }

                    $varName = substr($input, $start, $end - $start);
                    if (isset($env[$varName]) && is_string($env[$varName])) {
                        $output .= $env[$varName];
                    }
                    $i = $end;
                }
            } else {
                $output .= $input[$i];
                $i++;
            }
        }

        return $output;
    }

    /**
     * Parse a braced variable reference and resolve it.
     *
     * @param string $varRef The content inside ${...}
     * @param array<string, string|array> $env Environment to look up variables
     * @return string The resolved value
     */
    private static function parseAndResolve(string $varRef, array $env): string
    {
        $defaultValue = null;
        $useDefaultIfEmpty = false;
        $useAlternate = false;
        $varEnd = 0;
        $refLen = strlen($varRef);

        // Look for :- or :+ or just - syntax
        while ($varEnd < $refLen) {
            if ($varRef[$varEnd] === ':' && $varEnd + 1 < $refLen) {
                if ($varRef[$varEnd + 1] === '-') {
                    $useDefaultIfEmpty = true;
                    $defaultValue = substr($varRef, $varEnd + 2);
                    break;
                } elseif ($varRef[$varEnd + 1] === '+') {
                    $useAlternate = true;
                    $defaultValue = substr($varRef, $varEnd + 2);
                    break;
                }
            } elseif ($varRef[$varEnd] === '-' && !$useDefaultIfEmpty) {
                // ${VAR-default} without colon
                $defaultValue = substr($varRef, $varEnd + 1);
                break;
            }
            $varEnd++;
        }

        $varName = ($defaultValue !== null) ? substr($varRef, 0, $varEnd) : $varRef;

        // Get value from environment
        $value = isset($env[$varName]) && is_string($env[$varName]) ? $env[$varName] : null;

        if ($useAlternate) {
            // ${VAR:+alternate} - use alternate if VAR is set and non-empty
            if ($value !== null && $value !== '') {
                return $defaultValue ?? '';
            }
            return '';
        }

        if ($value !== null) {
            if ($useDefaultIfEmpty && $value === '' && $defaultValue !== null) {
                return $defaultValue;
            }
            return $value;
        }

        // Value not found
        return $defaultValue ?? '';
    }

    private static function isKeyChar(string $c): bool
    {
        return ($c >= 'A' && $c <= 'Z') ||
               ($c >= 'a' && $c <= 'z') ||
               ($c >= '0' && $c <= '9') ||
               $c === '_';
    }
}
