<?php
return [
    'directory_list' => [
        'src'
    ],
    'plugin_config' => [
        // This is slow
        'typo_check_comments_and_strings' => true,
    ],
    'plugins' => [
        '../src/TypoCheckPlugin.php',
    ],
];
