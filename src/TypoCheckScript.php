<?php declare(strict_types=1);

namespace PhanTypoCheck;

require_once __DIR__ . '/TypoCheckUtils.php';

use function count;
use function fclose;
use function file_exists;
use function fwrite;
use function fopen;
use function in_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_encode;
use function printf;
use function stream_get_contents;
use const PHP_EOL;
use const STDERR;

/**
 * The implementation of `phptypocheck`, a standalone script for analyzing php files/folders
 */
class TypoCheckScript
{
    /** @var int the number of errors that were printed */
    private static $error_count = 0;

    private static function printUsage(string $message = null)
    {
        global $argv;
        if ($message) {
            fwrite(STDERR, $message . "\n\n");
        }
        $help = <<<EOT
Usage: {$argv[0]} [--help|-h|help] [--extensions=php,html] path/to/file.php path/to/folder

  -h, --help, help:
    Print this help text

  -p, --plaintext
    Parse the files as plaintext instead of as PHP.

  --extensions=php,html
    When analyzing folders, check for typos in files with these extensions. Defaults to php.
    (Must be a single argument with '=')
    If the value is the empty string, then analyze all extensions.

EOT;

        fwrite(STDERR, $help);
    }

    public static function main() : int
    {
        global $argv;
        if (count($argv) <= 1) {
            self::printUsage("Error: Expected 1 or more files or folders to analyze");
            exit(1);
        }
        // TODO: make this configurable
        $file_extensions = ['php'];
        $checked_files = [];
        $args = array_slice($argv, 1);
        $plaintext = false;
        self::$error_count = 0;

        foreach ($args as $i => $opt) {
            if (in_array($opt, ['-h', '--help', 'help'])) {
                self::printUsage();
                exit(0);
            }
            if (($opt[0] ?? '') !== '-') {
                continue;
            }
            if (in_array($opt, ['-p', '--plaintext'])) {
                $plaintext = true;
                unset($args[$i]);
                continue;
            }
            if (preg_match('/^--extensions=(.*)$/', $opt, $matches)) {
                $file_extensions_string = $matches[1];
                $file_extensions = $file_extensions_string !== '' ? explode(',', $file_extensions_string) : [];
                unset($args[$i]);
                continue;
            }
        }

        foreach ($args as $file) {
            if (!file_exists($file)) {
                fwrite(STDERR, "Failed to find file/folder '$file'" . PHP_EOL);
                continue;
            }
            if (is_file($file)) {
                self::checkFile($file, $plaintext, $checked_files);
            } elseif (is_dir($file)) {
                self::checkFolderRecursively($file, $file_extensions, $plaintext, $checked_files);
            }
        }
        return self::$error_count;
    }

    private static function checkFolderRecursively(string $directory_name, array $file_extensions, bool $plaintext, array &$checked_files)
    {
        try {
            $iterator = new \CallbackFilterIterator(
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator(
                        $directory_name,
                        \RecursiveDirectoryIterator::FOLLOW_SYMLINKS
                    )
                ),
                static function (\SplFileInfo $file_info) use ($file_extensions) : bool {
                    if ($file_extensions && !in_array($file_info->getExtension(), $file_extensions, true)) {
                        return false;
                    }

                    if (!$file_info->isFile() || !$file_info->isReadable()) {
                        if ($file_extensions) {
                            $file_path = $file_info->getRealPath();
                            \error_log("Unable to read file {$file_path}");
                        }
                        return false;
                    }

                    return true;
                }
            );
            $file_list = \array_keys(\iterator_to_array($iterator));
        } catch (\Exception $e) {
            fwrite(STDERR, "Failed reading files in directory '$directory_name': " . $e->getMessage() . PHP_EOL);
            return;
        }

        // Normalize leading './' in paths.
        $normalized_file_list = [];
        foreach ($file_list as $file_path) {
            // @phan-suppress-next-line PhanPartialTypeMismatchArgumentInternal
            $file_path = \preg_replace('@^(\.[/\\\\]+)+@', '', $file_path);
            $normalized_file_list[$file_path] = $file_path;
        }
        \usort($normalized_file_list, static function (string $a, string $b) : int {
            // Sort lexicographically by paths **within the results for a directory**,
            // to work around some file systems not returning results lexicographically.
            // Keep directories together by replacing directory separators with the null byte
            // (E.g. "a.b" is lexicographically less than "a/b", but "aab" is greater than "a/b")
            return \strcmp(\preg_replace("@[/\\\\]+@", "\0", $a), \preg_replace("@[/\\\\]+@", "\0", $b));
        });
        foreach ($normalized_file_list as $file) {
            // @phan-suppress-next-line PhanPossiblyNullTypeArgument
            self::checkFile($file, $plaintext, $checked_files);
        }
    }

    const TOKEN_TO_DESCRIPTION = [
        \T_ENCAPSED_AND_WHITESPACE   => 'a string literal',
        \T_CONSTANT_ENCAPSED_STRING  => 'a string literal',
        \T_VARIABLE                  => 'a variable',
        \T_INLINE_HTML               => 'inline HTML',
        \T_COMMENT                   => 'a comment',
        \T_DOC_COMMENT               => 'a comment',
        \T_STRING                    => 'a token',
    ];

    private static function checkFile(string $file, bool $plaintext, array &$checked_files)
    {
        if (isset($checked_files[$file])) {
            return;
        }
        $checked_files[$file] = true;

        $fin = fopen($file, 'r');
        if (!is_resource($fin)) {
            fwrite(STDERR, "Failed to open file '$file'" . PHP_EOL);
            return;
        }
        // @phan-suppress-next-line PhanUndeclaredVariable https://github.com/phan/phan/issues/3403
        $contents = '';
        try {
            $start = fread($fin, 1024);
            if (!is_string($start)) {
                fwrite(STDERR, "Failed to read contents of '$file'" . PHP_EOL);
                return;
            }
            // Check for control characters, excluding tabs and newlines, and including DEL.
            if (preg_match('/[\x00-\x09\x14-\x1f\x7f]/', $start)) {
                fwrite(STDERR, "Skipping binary file '$file'" . PHP_EOL);
                return;
            }
            $remaining_contents = stream_get_contents($fin);
            if (!is_string($remaining_contents)) {
                fwrite(STDERR, "Failed to read contents of '$file'" . PHP_EOL);
                return;
            }

            $contents = $start . $remaining_contents;
        } finally {
            fclose($fin);
        }
        foreach (TypoCheckUtils::getTyposForText($contents, $plaintext) as $typo) {
            printf(
                "%s:%d Saw a possible typo %s in %s (%s)%s",
                $file,
                $typo->lineno,
                // @phan-suppress-next-line PhanPossiblyFalseTypeArgumentInternal
                json_encode($typo->word),
                self::TOKEN_TO_DESCRIPTION[$typo->token[0]] ?? 'unknown token type',
                TypoCheckUtils::makeSuggestionText($typo->suggestions, $typo->word),
                PHP_EOL
            );
            self::$error_count++;
        }
    }
}
