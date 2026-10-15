<?php

namespace App\Enums\Course;

enum CourseStatus: string
{
    case DRAFT = 'draft';
    case PENDING_PUBLISH = 'pending_publish';
    case PUBLISHED = 'published';
    case REJECTED = 'rejected';
    case ARCHIVED = 'archived';
}
