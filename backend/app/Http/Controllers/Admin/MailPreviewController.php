<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CashRegisterClosingReportMail;
use App\Mail\LowStockAlertMail;
use App\Mail\PettyCashWithdrawalMail;
use App\Mail\UserPasswordResetMail;
use App\Models\PettyCashTransaction;
use App\Models\User;
use Illuminate\Http\Response;

class MailPreviewController extends Controller
{
    private const TEMPLATES = [
        'password-reset',
        'low-stock',
        'petty-cash',
        'day-closing',
    ];

    public function index()
    {
        return response()->json([
            'status' => 'success',
            'data' => collect(self::TEMPLATES)->map(fn (string $slug) => [
                'slug' => $slug,
                'name' => match ($slug) {
                    'password-reset' => 'Restauracion de Contrasena',
                    'low-stock' => 'Alerta de Stock Critico',
                    'petty-cash' => 'Retiro de Caja Chica',
                    'day-closing' => 'Cierre de Caja Diario',
                },
            ])->values(),
        ]);
    }

    public function render(string $slug): Response
    {
        $mailable = match ($slug) {
            'password-reset' => $this->buildPasswordReset(),
            'low-stock' => $this->buildLowStock(),
            'petty-cash' => $this->buildPettyCash(),
            'day-closing' => $this->buildDayClosing(),
            default => abort(404, 'Plantilla no encontrada.'),
        };

        return response($mailable->render(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    private function buildPasswordReset(): UserPasswordResetMail
    {
        $user = User::first() ?? new User(['name' => 'Juan Perez', 'email' => 'juan@cronos.pos']);
        $resetUrl = url('/password-reset/preview-token');

        return new UserPasswordResetMail($user, $resetUrl);
    }

    private function buildLowStock(): LowStockAlertMail
    {
        return new LowStockAlertMail([
            ['sku' => 'BEB-001', 'name' => 'Coca-Cola 600ml', 'category' => 'Bebidas', 'minimum_stock' => 20, 'current_stock' => 3],
            ['sku' => 'SNK-015', 'name' => 'Sabritas Original 45g', 'category' => 'Snacks', 'minimum_stock' => 15, 'current_stock' => 0],
            ['sku' => 'LIM-008', 'name' => 'Jabon Liquido 500ml', 'category' => 'Limpieza', 'minimum_stock' => 10, 'current_stock' => 2],
        ]);
    }

    private function buildPettyCash(): PettyCashWithdrawalMail
    {
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
                'created_at' => now(),
            ]);
        }

        return new PettyCashWithdrawalMail($transaction);
    }

    private function buildDayClosing(): CashRegisterClosingReportMail
    {
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
    }
}
