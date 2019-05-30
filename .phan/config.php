<?php

use \Phan\Issue;

/**
 * This configuration will be read and overlaid on top of the
 * default configuration. Command line arguments will be applied
 * after this file is read.
 *
 * @see src/Phan/Config.php
 * See Config for all configurable options.
 *
 * A Note About Paths
 * ==================
 *
 * Files referenced from this file should be defined as
 *
 * ```
 *   Config::projectPath('relative_path/to/file')
 * ```
 *
 * where the relative path is relative to the root of the
 * project which is defined as either the working directory
 * of the phan executable or a path passed in via the CLI
 * '-d' flag.
 */
return [

    // If true, missing properties will be created when
    // they are first seen. If false, we'll report an
    // error message.
    "allow_missing_properties" => false,

    // Allow null to be cast as any type and for any
    // type to be cast to null.
    "null_casts_as_any_type" => false,

    // If enabled, scalars (int, float, bool, string, null)
    // are treated as if they can cast to each other.
    'scalar_implicit_cast' => false,

    // If enabled, Phan will warn if **any** type in the argument's type
    // cannot be cast to a type in the parameter's expected type.
    // Setting this to true will introduce a large number of false positives (and some bugs).
    // (For self-analysis, Phan has a large number of suppressions and file-level suppressions, due to \ast\Node being difficult to type check)
    'strict_param_checking' => true,

    // If enabled, Phan will warn if **any** type in a property assignment's type
    // cannot be cast to a type in the property's expected type.
    // Setting this to true will introduce a large number of false positives (and some bugs).
    // (For self-analysis, Phan has a large number of suppressions and file-level suppressions, due to \ast\Node being difficult to type check)
    'strict_property_checking' => true,

    // If enabled, Phan will warn if **any** type in the return statement's type
    // cannot be cast to a type in the method's declared return type.
    // Setting this to true will introduce a large number of false positives (and some bugs).
    // (For self-analysis, Phan has a large number of suppressions and file-level suppressions, due to \ast\Node being difficult to type check)
    'strict_return_checking' => true,

    // If true, seemingly undeclared variables in the global
    // scope will be ignored. This is useful for projects
    // with complicated cross-file globals that you have no
    // hope of fixing.
    'ignore_undeclared_variables_in_global_scope' => false,

    // Backwards Compatibility Checking
    'backward_compatibility_checks' => false,

    // If enabled, check all methods that override a
    // parent method to make sure its signature is
    // compatible with the parent's. This check
    // can add quite a bit of time to the analysis.
    'analyze_signature_compatibility' => true,

    'check_docblock_signature_return_type_match' => true,
    'check_docblock_signature_param_type_match' => true,
    'prefer_narrowed_phpdoc_param_type' => true,

    'unused_variable_detection' => true,

    // Run a quick version of checks that takes less
    // time
    "quick_mode" => false,

    // Enable or disable support for generic templated
    // class types.
    'generic_types_enabled' => true,

    // The minimum severity level to report on. This can be
    // set to Issue::SEVERITY_LOW, Issue::SEVERITY_NORMAL or
    // Issue::SEVERITY_CRITICAL.
    'minimum_severity' => Issue::SEVERITY_LOW,

    // The number of processes to fork off during the analysis
    // phase.
    'processes' => 1,

    // A list of directories that should be parsed for class and
    // method information. After excluding the directories
    // defined in exclude_analysis_directory_list, the remaining
    // files will be statically analyzed for errors.
    //
    // Thus, both first-party and third-party code being used by
    // your application should be included in this list.
    'directory_list' => [
        'src',
        '.phan',
        'vendor/phan/phan/src',
    ],
    'file_list' => [
        'phptypocheck',
    ],

    // List of case-insensitive file extensions supported by Phan.
    // (e.g. php, html, htm)
    'analyzed_file_extensions' => ['php'],

    // A directory list that defines files that will be excluded
    // from static analysis, but whose class and method
    // information should be included.
    //
    // Generally, you'll want to include the directories for
    // third-party code (such as "vendor/") in this list.
    //
    // n.b.: If you'd like to parse but not analyze 3rd
    //       party code, directories containing that code
    //       should be added to the `directory_list` as
    //       to `exclude_analysis_directory_list`.
    "exclude_analysis_directory_list" => [
        'vendor',
    ],

    // A list of plugin files to execute
    'plugins' => [
        'AlwaysReturnPlugin',
        'DollarDollarPlugin',
        'UnreachableCodePlugin',
        // NOTE: src/Phan/Language/Internal/FunctionSignatureMap.php mixes value without keys (as return type) with values having keys deliberately.
        'DuplicateArrayKeyPlugin',
        'PregRegexCheckerPlugin',
        'PrintfCheckerPlugin',
        'PhanSelfCheckPlugin',
        // 'src/TypoCheckPlugin.php',  // disabled to avoid issues in language server mode
    ],
];
