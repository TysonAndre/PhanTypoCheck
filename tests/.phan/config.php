<?php
return [
    'directory_list' => [
        'src'
    ],
    'plugin_config' => [
        // Default value
        'typo_check_comments_and_strings' => true,
        // path to a file with a list of typos to ignore
        'typo_check_ignore_words_file' => 'phantypocheck_ignore.txt',
    ],
    'plugins' => [
        '../src/TypoCheckPlugin.php',
    ],
];
