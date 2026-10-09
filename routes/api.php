<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CurrentStoreController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ItemController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
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

    /*
     * Business endpoints require a validated current store. The store is
     * resolved by the `current.store` middleware, never from request input.
     */
    Route::middleware('current.store')->group(function () {
        Route::get('/categories', [
            CategoryController::class,
            'index',
        ]);

        Route::post('/categories', [
            CategoryController::class,
            'store',
        ]);

        Route::get('/categories/{category}', [
            CategoryController::class,
            'show',
        ]);

        Route::put('/categories/{category}', [
            CategoryController::class,
            'update',
        ]);

        Route::patch('/categories/{category}', [
            CategoryController::class,
            'update',
        ]);

        Route::delete('/categories/{category}', [
            CategoryController::class,
            'destroy',
        ]);

        Route::get('/items', [
            ItemController::class,
            'index',
        ]);

        Route::post('/items', [
            ItemController::class,
            'store',
        ]);

        Route::get('/items/{item}', [
            ItemController::class,
            'show',
        ]);

        Route::put('/items/{item}', [
            ItemController::class,
            'update',
        ]);

        Route::patch('/items/{item}', [
            ItemController::class,
            'update',
        ]);

        Route::delete('/items/{item}', [
            ItemController::class,
            'destroy',
        ]);

        Route::get('/customers', [
            CustomerController::class,
            'index',
        ]);

        Route::post('/customers', [
            CustomerController::class,
            'store',
        ]);

        Route::get('/customers/{customer}', [
            CustomerController::class,
            'show',
        ]);

        Route::put('/customers/{customer}', [
            CustomerController::class,
            'update',
        ]);

        Route::patch('/customers/{customer}', [
            CustomerController::class,
            'update',
        ]);

        Route::delete('/customers/{customer}', [
            CustomerController::class,
            'destroy',
        ]);

        Route::get('/orders', [
            OrderController::class,
            'index',
        ]);

        Route::post('/orders', [
            OrderController::class,
            'store',
        ]);

        Route::get('/orders/{order}', [
            OrderController::class,
            'show',
        ]);

        Route::patch('/orders/{order}/fulfillment', [
            OrderController::class,
            'updateFulfillment',
        ]);

        Route::get('/orders/{order}/payments', [
            PaymentController::class,
            'indexForOrder',
        ]);

        Route::post('/orders/{order}/payments', [
            PaymentController::class,
            'store',
        ]);

        Route::get('/payments/{payment}', [
            PaymentController::class,
            'show',
        ]);

        Route::post('/payments/{payment}/void', [
            PaymentController::class,
            'void',
        ]);
    });
});
