<?php declare(strict_types=1);

namespace PhanTypoCheck;

class Config {
    /** @var bool whether to print the affected line alongside the issue message.  */
    public $with_context = false;
    /** @var bool whether to treat files as plaintext instead of php. */
    public $plaintext = false;
    /** @var list<string> file extensions to check */
    public $file_extensions = ['php'];
}
