<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\ApiTokenController;
use App\Http\Controllers\Api\DeviceController;
use App\Http\Controllers\Api\FolderController;
use App\Http\Controllers\Api\SyncEventController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Authenticated admin REST API. Bearer token (api_tokens) auth; results are
// scoped to the token owner (admins see everything). Base: /api/v1
Route::prefix('v1')->name('api.')->middleware('api.token')->group(function () {
    Route::get('me', fn (Request $r) => $r->user()->only(['id', 'name', 'email', 'role']));

    // Sync domain (owner-scoped; events inherit their folder's owner).
    Route::apiResource('folders', FolderController::class);
    Route::apiResource('devices', DeviceController::class);
    Route::apiResource('events', SyncEventController::class)->only(['index', 'show'])->parameters(['events' => 'syncEvent']);

    // Administration.
    Route::apiResource('users', UserController::class);
    Route::apiResource('api-tokens', ApiTokenController::class)->only(['index', 'store', 'destroy'])->parameters(['api-tokens' => 'apiToken']);
});

// Sync-agent API. The out-dialing agent (installed on a user's Windows/Mac/Linux
// computer) talks to this. `enroll` trades a one-time token for a permanent key;
// everything else authenticates with that key (agent.auth). Base: /api/v1/agent
Route::prefix('v1/agent')->name('api.agent.')->group(function () {
    Route::post('enroll', [AgentController::class, 'enroll'])->name('enroll');

    Route::middleware('agent.auth')->group(function () {
        Route::post('heartbeat', [AgentController::class, 'heartbeat'])->name('heartbeat');
        Route::get('poll', [AgentController::class, 'poll'])->name('poll');
        Route::post('runs/report', [AgentController::class, 'report'])->name('runs.report');
        Route::post('command-ack', [AgentController::class, 'commandAck'])->name('command-ack');
    });
});
