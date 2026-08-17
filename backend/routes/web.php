<?php

use App\Mail\CashRegisterClosingMail;
use App\Mail\CashRegisterClosingReportMail;
use App\Mail\LowStockAlertMail;
use App\Mail\PettyCashWithdrawalMail;
use App\Mail\UserPasswordResetMail;
use App\Mail\UserWelcomeMail;
use App\Models\CashRegisterClosing;
use App\Models\PettyCashTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
                $transaction = new PettyCashTransaction([
                    'amount' => 1500.00,
                    'reason' => 'provider_payment',
                    'description' => 'Pago parcial a proveedor de insumos de limpieza — factura FP-2026-0451',
                    'immutable_snapshot' => [
                        'operator_name' => 'Maria Lopez',
                        'operator_email' => 'maria@cronos.pos',
                        'security_seal' => hash('sha256', 'preview-seal-data'),
                    ],
                ]);
                $transaction->created_at = now();
                return new PettyCashWithdrawalMail($transaction);
            }

            return new PettyCashWithdrawalMail($transaction);
        });

        Route::get('/welcome', function () {
            $user = User::first() ?? new User(['name' => 'Ana Garcia', 'email' => 'ana.garcia@cronos.pos']);

            return new UserWelcomeMail($user, 'Xk9mPq2wR4tB');
        });

        Route::get('/cash-register-closing', function () {
            return new CashRegisterClosingReportMail(
                closingId: 'a1b2c3d4-e5f6-7890-abcd-ef1234567890',
                operatorName: 'Carlos Martinez',
                closingDate: now()->format('d/m/Y H:i:s'),
                totalAmount: 15420.50,
                paymentBreakdown: [
                    'Efectivo' => 8200.00,
                    'Tarjeta de Credito/Debito' => 5120.50,
                    'Transferencia' => 2100.00,
                ],
                isAutomated: true,
            );
        });

        /*
         * Multi-closing report (the one with the per-method breakdown).
         *
         * It prefers real rows so the preview shows the shape production
         * actually produces, and falls back to unsaved models when the local
         * database has no closings yet. The fixture is deliberately uneven —
         * one exact closing, one with a shortfall, one without a breakdown —
         * because those are the three renderings the template has to hold,
         * and the narrow viewport is where they break first.
         */
        Route::get('/cash-register-closings-report', function () {
            $closings = CashRegisterClosing::with('closedByUser:id,name')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();

            if ($closings->isEmpty()) {
                $operator = new User(['name' => 'Carlos Martinez', 'email' => 'carlos@cronos.pos']);

                $make = function (float $expected, float $declared, ?array $breakdown) use ($operator) {
                    $closing = new CashRegisterClosing([
                        'expected_amount' => $expected,
                        'declared_amount' => $declared,
                        'difference_amount' => round($declared - $expected, 2),
                        'payment_breakdown' => $breakdown,
                    ]);

                    $closing->created_at = now();
                    // Set as a relation: the model is never saved, so the
                    // foreign key has nothing to resolve against.
                    $closing->setRelation('closedByUser', $operator);

                    return $closing;
                };

                $breakdown = [
                    ['payment_method_id' => '1', 'name' => 'Efectivo', 'expected' => 26203.00, 'declared' => 26203.00, 'difference' => 0.0],
                    ['payment_method_id' => '2', 'name' => 'Tarjeta de Crédito/Débito', 'expected' => 2905.00, 'declared' => 2905.00, 'difference' => 0.0],
                    ['payment_method_id' => '3', 'name' => 'Transferencia', 'expected' => 4547.00, 'declared' => 4547.00, 'difference' => 0.0],
                ];

                $withShortfall = $breakdown;
                $withShortfall[0]['declared'] = 25_950.00;
                $withShortfall[0]['difference'] = -253.00;

                $closings = new EloquentCollection([
                    $make(33655.00, 33655.00, $breakdown),
                    $make(34155.00, 33902.00, $withShortfall),
                    $make(12000.00, 12000.00, null),
                ]);
            }

            return new CashRegisterClosingMail($closings, [
                'date_from' => now()->subDays(7)->toDateString(),
                'date_to' => now()->toDateString(),
            ]);
        });
    });
}
