<?php

namespace App\Enums\User;

enum UserStatus : string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
    case BLOCKED = 'blocked';
    case DELETED = 'deleted';

    case PENDING='pending';

}
