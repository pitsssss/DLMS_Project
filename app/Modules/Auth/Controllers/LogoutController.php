<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\Services\AuthService;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    public function logout(Request $request, AuthService $auth)
    {
        $auth->logout($request->user());

        return $this->successResponse(null, 'messages.auth.logout_success');
    }
}
