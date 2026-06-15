<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Promotion\PromotionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/2fa/verify', [AuthController::class, 'verify2fa']);
    });

    Route::middleware(['auth:sanctum', 'user.active'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);

        Route::prefix('2fa')->group(function () {
            Route::post('/setup', [TwoFactorController::class, 'setup']);
            Route::post('/confirm', [TwoFactorController::class, 'confirm']);
            Route::post('/disable', [TwoFactorController::class, 'disable']);
        });
    });
});

Route::middleware(['auth:sanctum', 'user.active'])->group(function () {
    Route::apiResource('categories', CategoryController::class);

    Route::get('products/grouped', [ProductController::class, 'grouped']);
    Route::apiResource('products', ProductController::class);

    Route::get('promotions/active', [PromotionController::class, 'active']);
    Route::get('promotions', [PromotionController::class, 'index']);
    Route::get('promotions/{promotion}', [PromotionController::class, 'show']);

    Route::middleware('role:admin,manager')->group(function () {
        Route::post('promotions', [PromotionController::class, 'store']);
        Route::put('promotions/{promotion}', [PromotionController::class, 'update']);
        Route::patch('promotions/{promotion}', [PromotionController::class, 'update']);
        Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy']);
    });
});
