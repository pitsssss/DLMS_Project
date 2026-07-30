<?php

use App\Modules\AIAgent\Controllers\AIAgentController;
use Illuminate\Support\Facades\Route;

Route::post('/message', [AIAgentController::class, 'sendMessage'])
    ->middleware('throttle:30,1');

Route::get('/sessions', [AIAgentController::class, 'listSessions']);

Route::get('/sessions/{session}', [AIAgentController::class, 'showSession'])
    ->whereNumber('session');

Route::post('/actions/{action}/confirm', [AIAgentController::class, 'confirmAction'])
    ->whereNumber('action')
    ->middleware('throttle:20,1');

Route::post('/actions/{action}/cancel', [AIAgentController::class, 'cancelAction'])
    ->whereNumber('action')
    ->middleware('throttle:20,1');

Route::post('/sessions/{session}/interactions', [AIAgentController::class, 'handleInteraction'])
    ->whereNumber('session')
    ->middleware('throttle:30,1');

Route::post('/sessions/{session}/documents', [AIAgentController::class, 'uploadSessionDocument'])
    ->whereNumber('session')
    ->middleware('throttle:20,1');
