<?php

return [
    "if (!\$user) { return response()->json(['message' => __('<string>')], 404); }" =>
        'Foo::bar("<1>")',
];
