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
    /** Lookup table for key characters */
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

    /**
     * Expand variables in a string.
     *
     * @param string $input The input string with variable references
     * @param array<string, string|array> $env Environment to look up variables
     * @return string The expanded string
     */
    public static function expand(string $input, array $env): string
    {
        // Fast path: no variables to expand
        $dollarPos = strpos($input, '$');
        if ($dollarPos === false) {
            return $input;
        }

        $output = [];
        $len = strlen($input);
        $i = 0;

        // Copy everything before first $
        if ($dollarPos > 0) {
            $output[] = substr($input, 0, $dollarPos);
            $i = $dollarPos;
        }

        while ($i < $len) {
            if ($input[$i] === '$' && $i + 1 < $len) {
                if ($input[$i + 1] === '{') {
                    // Find closing brace
                    $start = $i + 2;
                    $end = strpos($input, '}', $start);

                    if ($end === false) {
                        // No closing brace, copy literally
                        $output[] = '$';
                        $i++;
                        continue;
                    }

                    // Parse the variable reference
                    $varRef = substr($input, $start, $end - $start);
                    $output[] = self::parseAndResolve($varRef, $env);
                    $i = $end + 1;
                } else {
                    // $VAR without braces - find end of variable name
                    $start = $i + 1;
                    $end = $start;

                    while ($end < $len && isset(self::KEY_CHARS[$input[$end]])) {
                        $end++;
                    }

                    if ($end === $start) {
                        $output[] = '$';
                        $i++;
                        continue;
                    }

                    $varName = substr($input, $start, $end - $start);
                    if (isset($env[$varName]) && is_string($env[$varName])) {
                        $output[] = $env[$varName];
                    }
                    $i = $end;
                }
            } else {
                // Find next $ or end of string
                $nextDollar = strpos($input, '$', $i + 1);
                if ($nextDollar === false) {
                    $output[] = substr($input, $i);
                    break;
                }
                $output[] = substr($input, $i, $nextDollar - $i);
                $i = $nextDollar;
            }
        }

        return implode('', $output);
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
        // Look for :- or :+ or - using strpos (faster than char loop)
        $colonDash = strpos($varRef, ':-');
        if ($colonDash !== false) {
            $varName = substr($varRef, 0, $colonDash);
            $defaultValue = substr($varRef, $colonDash + 2);
            $value = isset($env[$varName]) && is_string($env[$varName]) ? $env[$varName] : null;
            return ($value !== null && $value !== '') ? $value : $defaultValue;
        }

        $colonPlus = strpos($varRef, ':+');
        if ($colonPlus !== false) {
            $varName = substr($varRef, 0, $colonPlus);
            $alternateValue = substr($varRef, $colonPlus + 2);
            $value = isset($env[$varName]) && is_string($env[$varName]) ? $env[$varName] : null;
            return ($value !== null && $value !== '') ? $alternateValue : '';
        }

        $dash = strpos($varRef, '-');
        if ($dash !== false) {
            $varName = substr($varRef, 0, $dash);
            $defaultValue = substr($varRef, $dash + 1);
            $value = isset($env[$varName]) && is_string($env[$varName]) ? $env[$varName] : null;
            return $value ?? $defaultValue;
        }

        // Simple variable reference
        return isset($env[$varRef]) && is_string($env[$varRef]) ? $env[$varRef] : '';
    }
}
