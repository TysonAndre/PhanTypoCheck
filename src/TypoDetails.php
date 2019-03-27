<?php declare(strict_types=1);

namespace PhanTypoCheck;

/**
 * Details about an individual typo
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
class TypoDetails
{
    /** @var string */
    public $word;

    /** @var array{0:int,1:string,2:int} the token from token_get_all containing $this->word */
    public $token;

    /** @var int the 1-based line number of this issue */
    public $lineno;

    /**
     * @var array<int,string> suggestions from right hand side of dictionary.txt.
     * If there is more than one element here, the last element is the reason why the fix should not be made (can be the empty string)
     */
    public $suggestions;

    /**
     * @param array<int,string> $suggestions 1 or more suggestions
     */
    public function __construct(string $word, array $token, int $lineno, array $suggestions)
    {
        $this->word = $word;
        $this->token = $token;
        $this->lineno = $lineno;
        $this->suggestions = $suggestions;
    }
}
