<?php

namespace App\Enums;

enum UserType: string
{
    case Citizen = 'citizen';
    case Employee = 'employee';
    case Admin = 'admin';
}
