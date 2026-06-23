<?php

use App\Mail\CashRegisterClosingReportMail;
use App\Mail\LowStockAlertMail;
use App\Mail\PettyCashWithdrawalMail;
use App\Mail\UserPasswordResetMail;
use App\Models\PettyCashTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/password-reset/{user}', function () {
    return redirect('/login');
})->name('password.reset');

if (app()->environment('local')) {
    Route::prefix('mail-preview')->group(function () {
        Route::get('/password-reset', function () {
            $user = User::first() ?? new User(['name' => 'Juan Perez', 'email' => 'juan@cronos.pos']);
            $resetUrl = url('/password-reset/preview-token');

            return new UserPasswordResetMail($user, $resetUrl);
        });

        Route::get('/low-stock', function () {
            $products = [
                ['sku' => 'BEB-001', 'name' => 'Coca-Cola 600ml', 'category' => 'Bebidas', 'minimum_stock' => 20, 'current_stock' => 3],
                ['sku' => 'SNK-015', 'name' => 'Sabritas Original 45g', 'category' => 'Snacks', 'minimum_stock' => 15, 'current_stock' => 0],
                ['sku' => 'LIM-008', 'name' => 'Jabon Liquido 500ml', 'category' => 'Limpieza', 'minimum_stock' => 10, 'current_stock' => 2],
                ['sku' => 'BEB-042', 'name' => 'Agua Natural 1L', 'category' => 'Bebidas', 'minimum_stock' => 30, 'current_stock' => 5],
            ];

            return new LowStockAlertMail($products);
        });

        Route::get('/petty-cash', function () {
            $transaction = PettyCashTransaction::latest()->first();

            if (! $transaction) {
                return new PettyCashWithdrawalMail(new PettyCashTransaction([
                    'amount' => 1500.00,
                    'reason' => 'provider_payment',
                    'description' => 'Pago parcial a proveedor de insumos de limpieza — factura FP-2026-0451',
                    'immutable_snapshot' => [
                        'operator_name' => 'Maria Lopez',
                        'operator_email' => 'maria@cronos.pos',
                        'security_seal' => hash('sha256', 'preview-seal-data'),
                    ],
                    'created_at' => now(),
                ]));
            }

            return new PettyCashWithdrawalMail($transaction);
        });

        Route::get('/cash-register-closing', function () {
            return new CashRegisterClosingReportMail(
                closingId: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                operatorName: 'Carlos Martinez',
                closingDate: now()->format('d/m/Y H:i:s'),
                expectedAmount: 15420.50,
                declaredAmount: 15380.00,
                differenceAmount: -40.50,
                paymentBreakdown: [
                    ['method' => 'Efectivo', 'amount' => 8200.00],
                    ['method' => 'Tarjeta de Credito/Debito', 'amount' => 5120.50],
                    ['method' => 'Transferencia', 'amount' => 2100.00],
                ],
            );
        });
    });
}
