<?php

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
