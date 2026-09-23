<?php

declare(strict_types=1);

namespace GNesting\Enums;

enum UserType: string
{
    case Customer = 'customer';
    case Admin = 'admin';
}
