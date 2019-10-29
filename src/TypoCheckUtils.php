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
use function token_get_all;
use function trim;
use const PREG_OFFSET_CAPTURE;

require_once __DIR__ . '/LineCounter.php';
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
    public static function getTyposForText(string $contents, bool $plaintext = false) : array
    {
        $dictionary = self::getDictionary();
        $results = [];

        $analyze_text = static function (string $text, array $token) use ($dictionary, &$results) {
            // @phan-suppress-next-line PhanUnusedVariableReference the reference is used to preserve state
            $count_lines_before = static function (int $offset) use($text, $token) : int {
                if ($offset <= 0) {
                    return 0;
                }
                static $line_counter;
                if ($line_counter === null) {
                    $line_counter = new LineCounter($text, $token);
                }
                return $line_counter->getLineNumberForOffset($offset);
            };
            preg_match_all('/[a-z0-9]{3,}(?:\'[a-z]+)?/i', $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $match) {
                list($word, $offset) = $match;
                $suggestions = $dictionary[strtolower($word)] ?? null;
                if ($suggestions === null) {
                    // Analyze anything resembling camelCase, CamelCase, or snake_case
                    if (preg_match('/(?:[a-z].*[A-Z]|_)/', $word)) {
                        preg_match_all('/[a-z]+|[A-Z](?:[a-z]+|[A-Z]+(?![a-z]))/', $word, $matches);
                        if (count($matches[0]) >= 2) {
                            foreach ($matches[0] as $inner_word) {
                                $suggestions = $dictionary[strtolower($inner_word)] ?? null;
                                if ($suggestions === null) {
                                    continue;
                                }
                                $lineno = (int)($token[2]) + $count_lines_before($offset);
                                $details = self::makeTypoDetails($inner_word, $token, $suggestions, $lineno);
                                if ($details) {
                                    $results[] = $details;
                                }
                            }
                        }
                    }
                    continue;
                }
                // Edge case in php 7.0: warns if length is 0
                $lineno = (int)($token[2]) + $count_lines_before($offset);
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
                $details = self::makeTypoDetails($word, $token, $suggestions, (int)($token[2]));
                if ($details) {
                    $results[] = $details;
                }
            }
        };
        if ($plaintext) {
            $tokens = [[T_INLINE_HTML, $contents, 1]];
        } else {
            $tokens = @token_get_all($contents);
        }
        foreach ($tokens as $token) {
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

    /**
     * @param array{0:int,1:string,2:int} $token
     * @param array<int,string> $suggestions
     * @return ?TypoDetails
     */
    private static function makeTypoDetails(string $word, array $token, array $suggestions, int $lineno) {
        $did_skip_word_with_apostrophe = false;
        foreach ($suggestions as $i => $suggestion) {
            if (!preg_match('/[^a-zA-Z0-9_\x7f-\xff]/', $suggestion)) {
                continue;
            }
            // This has characters that don't belong in a valid php token (e.g. has `'` or `-`)

            if (count($suggestions) < 2 || $i !== count($suggestions) - 1) {
                // And this is not the last commas separated value
                $did_skip_word_with_apostrophe = true;
                unset($suggestions[$i]);
            }
        }
        if ($did_skip_word_with_apostrophe) {
            if (count($suggestions) <= 1) {
                // the last value is always the empty string or a reason to consider not fixing it
                return null;
            }
            $suggestions = array_values($suggestions);
        }
        return new TypoDetails($word, $token, $lineno, $suggestions);
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
