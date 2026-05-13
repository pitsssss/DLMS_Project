<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case Register = 'register';
    case ForgotPassword = 'forgot_password';
}
