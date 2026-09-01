<?php

namespace Cofa\ApiDocs\Tests\Fixtures\Enums;

enum UserStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';
    case Banned = 'banned';
}
