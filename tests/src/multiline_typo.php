<?php
// PhanTypoCheck does not count newlines when they're escaped
echo gettext("\n\n\ninvlaid text");

echo gettext("\n\x0a\12INVLAID text
    Invlaid");


echo gettext("
this
is
invalid");
echo gettext("
\n'wasn' is a typo");
