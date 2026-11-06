<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

final class EmailVerificationRequired extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct(__('openbook.verify_email.content_required'));
    }
}
