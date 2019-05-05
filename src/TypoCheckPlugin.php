<?php declare(strict_types=1);

namespace PhanTypoCheck;

use ast\Node;
use Closure;
use Phan\AST\ContextNode;
use Phan\CodeBase;
use Phan\Config;
use Phan\Issue;
use Phan\Language\Context;
use Phan\Language\Element\Func;
use Phan\Library\StringUtil;
use Phan\PluginV3;
use Phan\PluginV3\AfterAnalyzeFileCapability;
use Phan\PluginV3\AnalyzeFunctionCallCapability;
use Phan\Suggestion;
use function array_key_exists;
use function count;
use function explode;
use function extension_loaded;
use function file_get_contents;
use function fwrite;
use function is_array;
use function is_file;
use function is_string;
use function json_encode;
use function preg_match_all;
use function strtolower;
use function trim;
use const STDERR;

require_once __DIR__ . '/TypoCheckUtils.php';

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
class TypoCheckPlugin extends PluginV3 implements
    AfterAnalyzeFileCapability,
    AnalyzeFunctionCallCapability
{
    /** @var bool */
    private $check_tokens;

    public function __construct()
    {
        $this->check_tokens = Config::getValue('plugin_config')['typo_check_comments_and_strings'] ?? true;
        if (extension_loaded('pcntl') && self::isRunningInBackground()) {
            // load dictionary before forking processes instead of repeatedly reloading it.
            TypoCheckUtils::getDictionary();
        }
    }

    private function analyzeText(CodeBase $code_base, Context $context, Func $function, string $pattern) : void
    {
        $dictionary = TypoCheckUtils::getDictionary();
        preg_match_all('/\w{3,}(?:\'\w+)?/', $pattern, $matches);
        foreach ($matches[0] as $word) {
            $suggestions = $dictionary[strtolower($word)] ?? null;
            if ($suggestions === null) {
                continue;
            }
            self::emitIssueIfNotKnownTypo(
                $code_base,
                $context,
                'PhanPluginPossibleTypoGettext',
                'Call to {FUNCTION}() was passed an invalid word {STRING_LITERAL} in {STRING_LITERAL}',
                [$function->getName(), json_encode($word), StringUtil::encodeValue($pattern)],
                $word,
                $suggestions
            );
        }
    }

    private static function makeSuggestion(array $suggestions, string $original_word) : Suggestion
    {
        $suggestion = TypoCheckUtils::makeSuggestionText($suggestions, $original_word);
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
         */
        $gettext_callback = function (
            CodeBase $code_base,
            Context $context,
            Func $function,
            array $args
        ) : void {
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
        ) : void {
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
        \T_ENCAPSED_AND_WHITESPACE   => 'PhanPluginPossibleTypoStringLiteral',
        \T_CONSTANT_ENCAPSED_STRING  => 'PhanPluginPossibleTypoStringLiteral',
        \T_VARIABLE                  => 'PhanPluginPossibleTypoVariable',
        \T_INLINE_HTML               => 'PhanPluginPossibleTypoInlineHTML',
        \T_COMMENT                   => 'PhanPluginPossibleTypoComment',
        \T_DOC_COMMENT               => 'PhanPluginPossibleTypoComment',
        \T_STRING                    => 'PhanPluginPossibleTypoToken',
    ];

    private static function getIssueName(array $token) : string
    {
        return self::TOKEN_ISSUE_MAP[$token[0]] ?? 'PhanPluginPossibleTypoUnknown';
    }

    public function afterAnalyzeFile(
        CodeBase $code_base,
        Context $context,
        string $file_contents,
        Node $unused_node
    ) : void {
        if (!$this->check_tokens) {
            return;
        }
        foreach (TypoCheckUtils::getTyposForText($file_contents) as $typo) {
            self::emitIssueIfNotKnownTypo(
                $code_base,
                clone($context)->withLineNumberStart($typo->lineno),
                self::getIssueName($typo->token),
                'Saw an invalid word {STRING_LITERAL}',
                [json_encode($typo->word)],
                $typo->word,
                $typo->suggestions
            );
        }
    }

    /**
     * Emit an issue if there are no configurations suppressing the issue on $word
     * @param array<int,string> $arguments
     * @param array<int,string> $suggestions
     */
    private static function emitIssueIfNotKnownTypo(
        CodeBase $code_base,
        Context $context,
        string $issue_name,
        string $issue_template,
        array $arguments,
        string $word,
        array $suggestions
    ) : void {
        if (self::isKnownTypo($word)) {
            return;
        }
        self::emitIssue(
            $code_base,
            $context,
            $issue_name,
            $issue_template,
            $arguments,
            Issue::SEVERITY_NORMAL,
            Issue::REMEDIATION_B,
            Issue::TYPE_ID_UNKNOWN,
            self::makeSuggestion($suggestions, $word)
        );
    }

    public static function isKnownTypo(string $word) : bool
    {
        $word = strtolower($word);
        return array_key_exists($word, self::getKnownTypoSet());
    }

    private static $known_typo_set = null;

    private static function getKnownTypoSet() : array
    {
        return self::$known_typo_set ?? self::$known_typo_set = self::computeKnownTypoSet();
    }

    private static function computeKnownTypoSet() : array
    {
        $result = [];
        $typo_file = Config::getValue('plugin_config')['typo_check_ignore_words_file'] ?? null;
        if ($typo_file && is_string($typo_file)) {
            $typo_file = Config::projectPath($typo_file);
            if (is_file($typo_file)) {
                foreach (explode("\n", file_get_contents($typo_file) ?: '') as $line) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    if ($line[0] === '#') {
                        continue;
                    }
                    $result[strtolower($line)] = true;
                }
            } else {
                fwrite(STDERR, "typo_check_ignore_words_file '$typo_file' is not a file\n");
            }
        }
        return $result;
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
