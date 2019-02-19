<?php
// Don't emit a warning, the suggested fixes aren't possible for word tokens.
const DONT_WARN_ABOUT_THIS = 1;
echo DONT_WARN_ABOUT_THIS;
// will warn
echo 'DONT WARN';
