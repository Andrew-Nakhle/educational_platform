<?php

namespace App\Enums\Course;

enum VideoStatus: string
{
    case PROCESSING = 'processing';
    case READY = 'ready';
    case FAILED = 'failed';
}
