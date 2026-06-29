<?php

namespace App\Http\Controllers\Admin;

use App\Builders\TicketBuilder;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\PrinterService;
use Illuminate\Http\JsonResponse;

class PrintTicketController extends Controller
{
    public function __construct(
        private readonly TicketBuilder $builder,
        private readonly PrinterService $printerService,
    ) {}

    public function __invoke(Order $order): JsonResponse
    {
        $order->load(['items.product', 'paymentMethod', 'cashRegister.user', 'ticketConfig']);

        $ticketConfig = $order->ticketConfig;

        if (! $ticketConfig) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_PRINT_NO_TICKET_CONFIG',
                'message' => 'La orden no tiene una configuración de ticket asociada.',
            ], 422);
        }

        $dto = $this->builder->build($order, $ticketConfig);
        $printerData = $this->printerService->generateBase64($dto);

        return response()->json([
            'status' => 'success',
            'message' => 'Datos de impresión generados correctamente.',
            'printer_data' => $printerData,
        ]);
    }
}
