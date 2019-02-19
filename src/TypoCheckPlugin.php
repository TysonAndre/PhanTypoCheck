<?php declare(strict_types=1);

use ast\Node;
use Phan\AST\ContextNode;
use Phan\AST\TolerantASTConverter\InvalidNodeException;
use Phan\CodeBase;
use Phan\Config;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Language\Element\Func;
use Phan\Library\StringUtil;
use Phan\PluginV2;
use Phan\PluginV2\AfterAnalyzeFileCapability;
use Phan\PluginV2\AnalyzeFunctionCallCapability;
use Phan\Suggestion;

/**
 * This plugin checks for typos in code, and is aware of php string escaping rules.
 * It also detects when typos are passed to `gettext`.
 *
 * - afterAnalyzeFile
 *   Analyzes the tokens of the file.
 * - getAnalyzeFunctionCallClosures
 *   This method returns a map from function/method FQSEN to closures that are called on invocations of those closures.
 *
 * ------------------------
 *
 * PhanTypoCheck is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.

 * PhanTypoCheck is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.

 * You should have received a copy of the GNU General Public License
 * along with PhanTypoCheck.  If not, see <https://www.gnu.org/licenses/>.
 */
class TypoCheckPlugin extends PluginV2 implements
    AfterAnalyzeFileCapability,
    AnalyzeFunctionCallCapability
{

    /** @var ?array<string,array<int,string>> */
    private static $dictionary = null;

    /** @var bool */
    private $check_comments;

    public function __construct()
    {
        $this->check_comments = Config::getValue('plugin_config')['typo_check_comments_and_strings'] ?? true;
        if (extension_loaded('pcntl') && self::isRunningInBackground()) {
            // load dictionary before forking processes instead of repeatedly reloading it.
            self::getDictionary();
        }
    }

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

    /** @return void */
    private function analyzeText(CodeBase $code_base, Context $context, Func $function, string $pattern)
    {
        $dictionary = self::getDictionary();
        preg_match_all('/\w{3,}(?:\'\w+)?/', $pattern, $matches);
        foreach ($matches[0] as $word) {
            $suggestions = $dictionary[strtolower($word)] ?? null;
            if ($suggestions === null) {
                continue;
            }
            $this->emitIssue(
                $code_base,
                $context,
                'PhanPluginPossibleTypoGettext',
                'Call to {FUNCTION}() was passed an invalid word {STRING_LITERAL} in {STRING_LITERAL}',
                [$function->getName(), StringUtil::encodeValue($word), StringUtil::encodeValue($pattern)],
                Issue::SEVERITY_NORMAL,
                Issue::REMEDIATION_B,
                Issue::TYPE_ID_UNKNOWN,
                self::makeSuggestion($suggestions, $word)
            );
        }
    }

    private static function makeSuggestion(array $suggestions, string $original_word) : Suggestion
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
        $suggestion = 'Did you mean ' . implode(' or ', array_map([StringUtil::class, 'encodeValue'], $suggestions)) . '?';
        if ($reason) {
            $suggestion .= " : not always fixable: $reason";
        }
        return Suggestion::fromString($suggestion);
    }

    /**
     * @param CodeBase $code_base @phan-unused-param
     * @return array<string, Closure(CodeBase,Context,Func,array):void>
     */
    public function getAnalyzeFunctionCallClosures(CodeBase $code_base) : array
    {
        /**
         * @param array<int,Node|string|int|float> $args the nodes for the arguments to the invocation
         * @return void
         */
        $gettext_callback = function (
            CodeBase $code_base,
            Context $context,
            Func $function,
            array $args
        ) {
            if (count($args) < 1) {
                return;
            }
            $text = $args[0];
            if ($text instanceof Node) {
                $text = (new ContextNode($code_base, $context, $text))->getEquivalentPHPScalarValue();
            }
            if (\is_string($text)) {
                $this->analyzeText($code_base, $context, $function, $text);
            }
        };

        $ngettext_callback = function (
            CodeBase $code_base,
            Context $context,
            Func $function,
            array $args
        ) {
            for ($i = 0; $i < 2; $i++) {
                $text = $args[$i] ?? null;
                if ($text instanceof Node) {
                    $text = (new ContextNode($code_base, $context, $text))->getEquivalentPHPScalarValue();
                }
                if (\is_string($text)) {
                    $this->analyzeText($code_base, $context, $function, $text);
                }
            }
        };

        // TODO: Check that the callbacks have the right signatures in another PR?
        return [
            // call
            '_'                           => $gettext_callback,
            'gettext'                     => $gettext_callback,
            'ngettext'                    => $ngettext_callback,
        ];
    }

    const TOKEN_ISSUE_MAP = [
        T_ENCAPSED_AND_WHITESPACE   => 'PhanPluginPossibleTypoStringLiteral',
        T_CONSTANT_ENCAPSED_STRING  => 'PhanPluginPossibleTypoStringLiteral',
        T_VARIABLE                  => 'PhanPluginPossibleTypoVariable',
        T_INLINE_HTML               => 'PhanPluginPossibleTypoInlineHTML',
        T_COMMENT                   => 'PhanPluginPossibleTypoComment',
        T_DOC_COMMENT               => 'PhanPluginPossibleTypoComment',
        T_STRING                    => 'PhanPluginPossibleTypoToken',
    ];

    private static function getIssueName(array $token)
    {
        return self::TOKEN_ISSUE_MAP[$token[0]] ?? 'PhanPluginPossibleTypoUnknown';
    }

    public function afterAnalyzeFile(
        CodeBase $code_base,
        Context $context,
        string $file_contents,
        Node $unused_node
    ) {
        if (!$this->check_comments) {
            return;
        }
        $dictionary = self::getDictionary();

        $analyze_text = static function (string $text, array $token) use ($code_base, $context, $dictionary) {
            preg_match_all('/[a-z0-9]{3,}(?:\'[a-z]+)?/i', $text, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as $match) {
                list($word, $offset) = $match;
                $suggestions = $dictionary[strtolower($word)] ?? null;
                if ($suggestions === null) {
                    continue;
                }
                // Edge case in php 7.0: warns if length is 0
                $lineno = (int)($token[2]) + ($offset > 0 ? substr_count($text, "\n", 0, $offset) : 0);
                self::emitIssue(
                    $code_base,
                    clone($context)->withLineNumberStart($lineno),
                    self::getIssueName($token),
                    'Saw an invalid word {STRING_LITERAL}',
                    [StringUtil::encodeValue($word)],
                    Issue::SEVERITY_NORMAL,
                    Issue::REMEDIATION_B,
                    Issue::TYPE_ID_UNKNOWN,
                    self::makeSuggestion($suggestions, $word)
                );
            }
        };
        $analyze_identifier = static function (string $text, array $token) use ($code_base, $context, $dictionary) {
            // Try to extract everything from identifiers that are CamelCase, camelCase, or camelACRONYMCase.
            preg_match_all('/[a-z]+|[A-Z](?:[a-z]+|[A-Z]+(?![a-z]))/', $text, $matches);
            foreach ($matches[0] as $word) {
                $suggestions = $dictionary[strtolower($word)] ?? null;
                if ($suggestions === null) {
                    continue;
                }
                $did_skip_word_with_apostrophe = false;
                foreach ($suggestions as $i => $suggestion) {
                    if (strpos($suggestion, "'") === false) {
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
                self::emitIssue(
                    $code_base,
                    clone($context)->withLineNumberStart($lineno),
                    self::getIssueName($token),
                    'Saw an invalid word {STRING_LITERAL}',
                    [StringUtil::encodeValue($word)],
                    Issue::SEVERITY_NORMAL,
                    Issue::REMEDIATION_B,
                    Issue::TYPE_ID_UNKNOWN,
                    self::makeSuggestion($suggestions, $word)
                );
            }
        };
        foreach (@token_get_all($file_contents) as $token) {
            if (!is_array($token)) {
                continue;
            }
            switch ($token[0]) {
                case T_ENCAPSED_AND_WHITESPACE:
                    try {
                        // @phan-suppress-next-line PhanAccessMethodInternal
                        $text = \Phan\AST\TolerantASTConverter\StringUtil::parseEscapeSequences($token[1], '"');
                    } catch (InvalidNodeException $_) {
                        break;
                    }
                    $analyze_text($text, $token);
                    // decode
                    break;
                case T_CONSTANT_ENCAPSED_STRING:
                    // @phan-suppress-next-line PhanAccessMethodInternal
                    $text = \Phan\AST\TolerantASTConverter\StringUtil::parse($token[1]);
                    $analyze_text($text, $token);
                    break;
                case T_VARIABLE:
                    $analyze_identifier((string)substr($token[1], 1), $token);
                    break;
                case T_STRING:
                    $analyze_identifier($token[1], $token);
                    break;
                case T_COMMENT:
                case T_DOC_COMMENT:
                case T_INLINE_HTML:
                    $analyze_text($token[1], $token);
                    break;
            }
        }
    }

    /**
     * Check if this is running either in the language server mode or in daemon mode.
     *
     * This is useful for plugins to check if any expensive initialization should be done early (instead of repeatedly during analysis).
     *
     * TODO: Start using Phan's API when that's released.
     */
    public static function isRunningInBackground() : bool
    {
        // is this in daemon mode?
        if (Config::getValue('daemonize_socket') || Config::getValue('daemonize_tcp')) {
            return true;
        }
        // is this running as a language server?
        return is_array(Config::getValue('language_server_config'));
    }
}

// Every plugin needs to return an instance of itself at the
// end of the file in which it's defined.
return new TypoCheckPlugin();
