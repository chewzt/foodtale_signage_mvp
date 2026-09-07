<?php

return [
    // Laravel file `max:` is kilobytes. 512 MB is enough for menu loops;
    // TV boxes still download the whole file.
    'upload_max_kb' => (int) env('SIGNAGE_UPLOAD_MAX_KB', 524288),
];
