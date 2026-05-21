<?php

use App\Modules\AIAgent\Controllers\AIAgentController;
use Illuminate\Support\Facades\Route;

Route::post('/message', [AIAgentController::class, 'sendMessage'])
    ->middleware('throttle:30,1');

Route::get('/sessions', [AIAgentController::class, 'listSessions']);

Route::get('/sessions/{session}', [AIAgentController::class, 'showSession'])
    ->whereNumber('session');
