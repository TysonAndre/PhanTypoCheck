<?php declare(strict_types=1);

namespace PhanTypoCheck;

class LineCounter
{
    /** @var string the text used for counting lines */
    private $line_counting_text;
    /** @var int the length of the text */
    private $length;
    /** @var int the last checked byte offset. */
    private $current_offset = 0;
    /** @var int the 0-based line of the last checked offset */
    private $current_line = 0;

    /** @param array{0:int,1:string,2:int} $token the current token */
    public function __construct(string $text, array $token)
    {
        if (\in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE])) {
            // Parse this, but replace `"\n"`, `"\x0a"`, etc. with a single byte character that isn't a literal newline.
            $this->line_counting_text = StringUtil::parseWithNewlinePlaceholder($token[1]);
        } else {
            // There are no escape sequences
            $this->line_counting_text = $text;
        }
        $this->length = strlen($this->line_counting_text);
    }

    /**
     * @param int $offset - A 0-based byte offset
     * @return int - gets the 1-based line number for $offset
     */
    public function getLineNumberForOffset(int $offset) : int {
        if ($offset < 0) {
            $offset = 0;
        } elseif ($offset > $this->length) {
            $offset = $this->length;
        }
        $current_offset = $this->current_offset;
        if ($offset > $current_offset) {
            $this->current_line += \substr_count($this->line_counting_text, "\n", $current_offset, $offset - $current_offset);
            $this->current_offset = $offset;
        } elseif ($offset < $current_offset) {
            $this->current_line -= \substr_count($this->line_counting_text, "\n", $offset, $current_offset - $offset);
            $this->current_offset = $offset;
        }
        return $this->current_line;
    }

}
