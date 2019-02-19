PhanTypoCheck
=============

[![Build Status](https://travis-ci.org/TysonAndre/PhanTypoCheck.svg?branch=master)](https://travis-ci.org/TysonAndre/PhanTypoCheck)
[![Latest Stable Version](https://img.shields.io/packagist/v/tysonandre/phantypocheck.svg)](https://packagist.org/packages/tysonandre/phantypocheck)
[![License](https://img.shields.io/packagist/l/tysonandre/phantypocheck.svg)](https://github.com/tysonandre/phantypocheck/blob/master/LICENSE)


This Phan plugin checks for typos in PHP files, with low false positives.

It checks all string literals, inline html, and doc comments (on by default).
It also checks variables, element names, and element usages (using heuristics to guess individual words of `camelCase` or `snake_case` identifiers).

This also emits warnings if strings with typos are passed to calls to `gettext()`, `_()`, and `ngettext()`.

Installing
----------

This can be installed with composer

```
composer require --dev tysonandre/phantypocheck
```

After it is installed, add the relative path to `TypoCheckPlugin.php` to the plugins section of `.phan/config.php`, e.g.

```php
    'plugins' => [
       // other plugins,
       'vendor/tysonandre/phantypocheck/src/TypoCheckPlugin.php',
    ],
```

This can also be manually downloaded (the current version doesn't have external dependencies).

Details
-------

The typo checks use [dictionary.txt](https://github.com/codespell-project/codespell/blob/master/codespell_lib/data/dictionary.txt) from https://github.com/codespell-project/codespell/

- It might be easier to just use codespell, depending on the use case.

  However, that would not tell you what type of identifier the string occurred in (and allow filtering by that).

  It also has issues analyzing the start/end of single quoted strings.

Options
-------

`'plugin_config' => ['typo_check_comments_and_strings' => false],` can be added to Phan configuration to make this skip checking comments, strings, and inline HTML for typos.

More options will be added in the future.

License
-------

dictionary.txt is a derived work of English Wikipedia and is released under the Creative Commons Attribution-Share-Alike License 3.0 http://creativecommons.org/licenses/by-sa/3.0/
(according to https://github.com/codespell-project/codespell#license)

-----

PhanTypoCheck is available under the GPL v3 License (see [LICENSE](./LICENSE))

    PhanTypoCheck is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    PhanTypoCheck is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with PhanTypoCheck.  If not, see <https://www.gnu.org/licenses/>.
