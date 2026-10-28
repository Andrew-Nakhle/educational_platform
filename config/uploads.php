<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upload Size Limits
    |--------------------------------------------------------------------------
    |
    | Maximum upload sizes in kilobytes, used by the validation rules. PHP's
    | upload_max_filesize and post_max_size must be at least this large,
    | otherwise the upload is rejected before it reaches the application.
    |
    */

    'thumbnail_max_kb' => (int) env('THUMBNAIL_MAX_KB', 5120),

    'video_max_kb' => (int) env('VIDEO_MAX_KB', 2097152),

];
