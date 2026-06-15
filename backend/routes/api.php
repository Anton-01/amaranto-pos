<?php

use App\Http\Controllers\Admin\TrashController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Finance\AnalyticsController;
use App\Http\Controllers\Finance\PettyCashController;
use App\Http\Controllers\Logistics\StockMovementController;
use App\Http\Controllers\Profile\NotificationPreferenceController;
use App\Http\Controllers\Promotion\PromotionController;
use App\Http\Controllers\Sales\OrderController;
use App\Http\Controllers\Sales\TicketConfigController;
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

    Route::prefix('ticket-configs')->group(function () {
        Route::get('/', [TicketConfigController::class, 'index']);
        Route::get('/active', [TicketConfigController::class, 'active']);
        Route::get('/{ticketConfig}', [TicketConfigController::class, 'show']);
    });

    Route::middleware('role:admin,manager')->group(function () {
        Route::post('ticket-configs', [TicketConfigController::class, 'store']);
    });

    Route::get('orders', [OrderController::class, 'index']);
    Route::post('orders', [OrderController::class, 'store']);
    Route::get('orders/{order}', [OrderController::class, 'show']);

    Route::prefix('stock-movements')->group(function () {
        Route::get('/', [StockMovementController::class, 'index']);
        Route::post('/', [StockMovementController::class, 'store']);
        Route::get('/summary', [StockMovementController::class, 'summary']);
    });

    Route::middleware('role:admin,manager')->prefix('admin/users')->group(function () {
        Route::get('/', [UserController::class, 'index']);
        Route::post('/', [UserController::class, 'store']);
        Route::get('/roles', [UserController::class, 'roles']);
        Route::get('/{user}', [UserController::class, 'show']);
        Route::post('/{user}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::delete('/{user}', [UserController::class, 'destroy']);
        Route::post('/{user}/send-password-reset', [UserController::class, 'sendPasswordReset']);
        Route::post('/{user}/sessions/{sessionId}/revoke', [UserController::class, 'revokeSession']);
    });

    Route::middleware('role:admin,manager')->prefix('admin/trash')->group(function () {
        Route::get('/{type}', [TrashController::class, 'index']);
        Route::post('/{type}/{id}/restore', [TrashController::class, 'restore']);
        Route::delete('/{type}/{id}', [TrashController::class, 'forceDelete']);
    });

    Route::middleware('role:admin,manager')->prefix('analytics')->group(function () {
        Route::get('/sales-by-payment', [AnalyticsController::class, 'salesByPaymentMethod']);
        Route::get('/financial-summary', [AnalyticsController::class, 'financialSummary']);
        Route::get('/daily-trend', [AnalyticsController::class, 'dailyTrend']);
    });

    Route::prefix('profile/notifications')->group(function () {
        Route::get('/', [NotificationPreferenceController::class, 'index']);
        Route::put('/', [NotificationPreferenceController::class, 'update']);
    });

    Route::prefix('petty-cash')->group(function () {
        Route::get('/', [PettyCashController::class, 'index']);
        Route::post('/', [PettyCashController::class, 'store']);
        Route::get('/summary', [PettyCashController::class, 'summary']);
        Route::get('/{pettyCashTransaction}', [PettyCashController::class, 'show']);
        Route::get('/{pettyCashTransaction}/verify', [PettyCashController::class, 'verify']);
    });
});
