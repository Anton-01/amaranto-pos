<?php

namespace App\Http\Controllers\Dining;

use App\Http\Controllers\Controller;
use App\Http\Requests\Table\StoreTableRequest;
use App\Http\Requests\Table\UpdateTableRequest;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TableController extends Controller
{
    /**
     * Plano de mesas: estatus de cada mesa y consumo acumulado de las abiertas.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Table::with([
            'activeSession.user:id,name',
            'activeSession.order' => fn ($q) => $q->withCount('items'),
        ])->orderBy('zone')->orderBy('name');

        if ($request->filled('zone')) {
            $query->where('zone', $request->zone);
        }
        if ($request->filled('status') && in_array($request->status, Table::STATUSES, true)) {
            $query->where('status', $request->status);
        }
        // El plano operativo solo muestra mesas en servicio; el catalogo admin
        // pide `include_inactive` para poder administrar las dadas de baja.
        if (!$request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        $tables = $query->get();

        return response()->json([
            'status' => 'success',
            'data' => $tables->map(fn (Table $table) => $this->presentTable($table))->all(),
            'metadata' => [
                'zones' => Table::whereNotNull('zone')->distinct()->orderBy('zone')->pluck('zone'),
                'summary' => [
                    'total' => $tables->count(),
                    'available' => $tables->where('status', Table::STATUS_AVAILABLE)->count(),
                    'occupied' => $tables->where('status', Table::STATUS_OCCUPIED)->count(),
                    'reserved' => $tables->where('status', Table::STATUS_RESERVED)->count(),
                ],
            ],
        ]);
    }

    /**
     * Detalle de una mesa con el desglose completo de su cuenta viva.
     */
    public function show(Table $table): JsonResponse
    {
        $table->load([
            'activeSession.user:id,name',
            'activeSession.order' => fn ($q) => $q->withCount('items'),
            'activeSession.order.items.product:id,name,sku,sale_price',
            'activeSession.order.items.promotion:id,name,type,value',
        ]);

        return response()->json([
            'status' => 'success',
            'data' => $this->presentTable($table, withItems: true),
        ]);
    }

    public function store(StoreTableRequest $request): JsonResponse
    {
        $table = Table::create($request->validated() + ['status' => Table::STATUS_AVAILABLE]);

        return response()->json([
            'status' => 'success',
            'data' => $this->presentTable($table),
            'metadata' => ['message' => 'Mesa creada exitosamente.'],
        ], 201);
    }

    public function update(UpdateTableRequest $request, Table $table): JsonResponse
    {
        $data = $request->validated();

        // El estatus de una mesa ocupada lo gobierna su sesion, no el catalogo:
        // moverlo a mano dejaria la cuenta viva huerfana.
        if ($table->status === Table::STATUS_OCCUPIED && array_key_exists('status', $data) && $data['status'] !== Table::STATUS_OCCUPIED) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TABLE_OCCUPIED',
                'message' => 'La mesa tiene una cuenta abierta. Cierra el cobro antes de cambiar su estatus.',
            ], 422);
        }

        if ($table->status === Table::STATUS_OCCUPIED && array_key_exists('is_active', $data) && !$data['is_active']) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TABLE_OCCUPIED',
                'message' => 'No puedes desactivar una mesa con una cuenta abierta.',
            ], 422);
        }

        $table->update($data);

        return response()->json([
            'status' => 'success',
            'data' => $this->presentTable($table->fresh()),
            'metadata' => ['message' => 'Mesa actualizada exitosamente.'],
        ]);
    }

    public function destroy(Table $table): JsonResponse
    {
        $sessionsCount = $table->sessions()->count();

        if ($sessionsCount > 0) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TABLE_HAS_SESSIONS',
                'message' => 'No se puede eliminar la mesa porque tiene consumos historicos asociados.',
                'metadata' => [
                    'sessions_count' => $sessionsCount,
                    'suggestion' => 'Desactiva la mesa en lugar de eliminarla para conservar su historico.',
                ],
            ], 422);
        }

        $table->delete();

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Mesa eliminada exitosamente.'],
        ]);
    }

    /**
     * Serializa la mesa junto con el acumulado de su sesion activa.
     */
    private function presentTable(Table $table, bool $withItems = false): array
    {
        $session = $table->activeSession;
        $order = $session?->order;

        $payload = [
            'id' => $table->id,
            'name' => $table->name,
            'capacity' => $table->capacity,
            'zone' => $table->zone,
            'status' => $table->status,
            'is_active' => $table->is_active,
            'active_session' => null,
        ];

        if (!$session) {
            return $payload;
        }

        $payload['active_session'] = [
            'id' => $session->id,
            'order_id' => $session->order_id,
            'status' => $session->status,
            'guests' => $session->guests,
            'notes' => $session->notes,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'elapsed_minutes' => $session->opened_at ? (int) $session->opened_at->diffInMinutes(now()) : 0,
            'waiter' => $session->user ? ['id' => $session->user->id, 'name' => $session->user->name] : null,
            'items_count' => (int) ($order?->items_count ?? 0),
            'subtotal' => (float) ($order?->subtotal ?? 0),
            'iva_total' => (float) ($order?->iva_total ?? 0),
            'total' => (float) ($order?->total ?? 0),
        ];

        if ($withItems) {
            $payload['active_session']['items'] = $order
                ? $order->items->map(fn ($item) => [
                    'id' => $item->id,
                    'product_id' => $item->product_id,
                    'product_name' => $item->product?->name ?? 'Producto eliminado',
                    'product_sku' => $item->product?->sku,
                    'promotion' => $item->promotion ? ['id' => $item->promotion->id, 'name' => $item->promotion->name] : null,
                    'quantity' => $item->quantity,
                    'base_price_at_sale' => (float) $item->base_price_at_sale,
                    'discount_amount_at_sale' => (float) $item->discount_amount_at_sale,
                    'final_price_at_sale' => (float) $item->final_price_at_sale,
                    'added_at' => $item->created_at?->toIso8601String(),
                ])->all()
                : [];
        }

        return $payload;
    }
}
