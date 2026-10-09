<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CurrentStoreController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [
        AuthController::class,
        'register',
    ]);

    Route::post('/login', [
        AuthController::class,
        'login',
    ]);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [
        AuthController::class,
        'logout',
    ]);

    Route::get('/me', [
        UserController::class,
        'me',
    ]);

    Route::get('/current-store', [
        CurrentStoreController::class,
        'show',
    ]);

    Route::put('/current-store', [
        CurrentStoreController::class,
        'update',
    ]);
});
