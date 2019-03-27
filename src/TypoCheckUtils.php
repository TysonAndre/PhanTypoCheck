<?php declare(strict_types=1);

namespace PhanTypoCheck;

use InvalidArgumentException;
use RuntimeException;
use function array_map;
use function array_pop;
use function array_values;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function is_array;
use function preg_match;
use function preg_match_all;
use function stripos;
use function strtolower;
use function substr;
use function substr_count;
use function token_get_all;
use function trim;
use const PREG_OFFSET_CAPTURE;

require_once __DIR__ . '/TypoDetails.php';
require_once __DIR__ . '/StringUtil.php';

/**
 * This contains the parts of PhanTypoCheck that aren't related to Phan or php-ast.
 *
 * ------------------------
 *
 * PhanTypoCheck is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PhanTypoCheck is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with PhanTypoCheck.  If not, see <https://www.gnu.org/licenses/>.
 */
class TypoCheckUtils
{

    /** @var ?array<string,array<int,string>> */
    private static $dictionary = null;

    public static function getDictionary()
    {
        return self::$dictionary ?? (self::$dictionary = self::loadDictionary());
    }

    private static function loadDictionary() : array
    {
        $file = dirname(__DIR__) . '/data/dictionary.txt';
        $contents = file_get_contents($file);
        if (!$contents) {
            throw new RuntimeException("failed to load $file");
        }
        $result = [];
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if (stripos($line, '->') === false) {
                continue;
            }
            list($typo, $corrections_string) = explode('->', $line, 2);
            $corrections = explode(',', $corrections_string);
            $result[$typo] = $corrections;
        }
        return $result;
    }

    /**
     * @return array<int,TypoDetails>
     * Maps line number to details about that typo
     */
    public static function getTyposForText(string $contents) : array
    {
        $dictionary = self::getDictionary();
        $results = [];

        $analyze_text = static function (string $text, array $token) use ($dictionary, &$results) {
            preg_match_all('/[a-z0-9]{3,}(?:\'[a-z]+)?/i', $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $match) {
                list($word, $offset) = $match;
                $suggestions = $dictionary[strtolower($word)] ?? null;
                if ($suggestions === null) {
                    continue;
                }
                // Edge case in php 7.0: warns if length is 0
                $lineno = (int)($token[2]) + ($offset > 0 ? substr_count($text, "\n", 0, $offset) : 0);
                $results[] = new TypoDetails($word, $token, $lineno, $suggestions);
            }
        };
        $analyze_identifier = static function (string $text, array $token) use ($dictionary, &$results) {
            // Try to extract everything from identifiers that are CamelCase, camelCase, or camelACRONYMCase.
            preg_match_all('/[a-z]+|[A-Z](?:[a-z]+|[A-Z]+(?![a-z]))/', $text, $matches);
            foreach ($matches[0] as $word) {
                $suggestions = $dictionary[strtolower($word)] ?? null;
                if ($suggestions === null) {
                    continue;
                }
                $did_skip_word_with_apostrophe = false;
                foreach ($suggestions as $i => $suggestion) {
                    if (!preg_match('/[^a-zA-Z0-9_\x7f-\xff]/', $suggestion)) {
                        // This replacement definitely isn't a valid php token (e.g. has `'` or `-`)
                        continue;
                    }
                    if (count($suggestions) < 2 || $i !== count($suggestions) - 1) {
                        $did_skip_word_with_apostrophe = true;
                        unset($suggestions[$i]);
                    }
                }
                if ($did_skip_word_with_apostrophe) {
                    if (count($suggestions) <= 1) {
                        // the last value is always the empty string or a reason to consider not fixing it
                        continue;
                    }
                    $suggestions = array_values($suggestions);
                }
                $lineno = (int)($token[2]);
                $results[] = new TypoDetails($word, $token, $lineno, $suggestions);
            }
        };
        foreach (@token_get_all($contents) as $token) {
            if (!is_array($token)) {
                continue;
            }
            switch ($token[0]) {
                case \T_ENCAPSED_AND_WHITESPACE:
                    try {
                        $text = StringUtil::parseEscapeSequences($token[1], '"');
                    } catch (InvalidArgumentException $_) {
                        break;
                    }
                    $analyze_text($text, $token);
                    // decode
                    break;
                case \T_CONSTANT_ENCAPSED_STRING:
                    $text = StringUtil::parse($token[1]);
                    $analyze_text($text, $token);
                    break;
                case \T_VARIABLE:
                    $analyze_identifier((string)substr($token[1], 1), $token);
                    break;
                case \T_STRING:
                    $analyze_identifier($token[1], $token);
                    break;
                case \T_COMMENT:
                case \T_DOC_COMMENT:
                case \T_INLINE_HTML:
                    $analyze_text($token[1], $token);
                    break;
            }
        }
        return $results;
    }

    public static function makeSuggestionText(array $suggestions, string $original_word) : string
    {
        $suggestions = array_map('trim', $suggestions);

        if (count($suggestions) > 1) {
            // empty string for no reason
            $reason = array_pop($suggestions);
        } else {
            $reason = null;
        }
        if (preg_match('/[a-z]/', $original_word, $matches, PREG_OFFSET_CAPTURE)) {
            if ($matches[0][1] > 0) {
                // The first character of the original word is uppercase
                $suggestions = array_map('ucfirst', $suggestions);
            }
        } else {
            // The word is uppercase
            $suggestions = array_map('strtoupper', $suggestions);
        }
        $suggestion = 'Did you mean ' . implode(' or ', array_map('json_encode', $suggestions)) . '?';
        if ($reason) {
            $suggestion .= " : not always fixable: $reason";
        }
        return $suggestion;
    }
}
