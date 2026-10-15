<?php

namespace App\Enums\Course;

enum VideoResolution: string
{
    case P360 = '360p';
    case P480 = '480p';
    case P720 = '720p';
    case P1080 = '1080p';
}
