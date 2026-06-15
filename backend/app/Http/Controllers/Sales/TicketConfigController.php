<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\TicketConfig\StoreTicketConfigRequest;
use App\Models\TicketConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class TicketConfigController extends Controller
{
    public function index(): JsonResponse
    {
        $configs = TicketConfig::orderByDesc('version')->get();

        return response()->json([
            'status' => 'success',
            'data' => $configs,
        ]);
    }

    public function active(): JsonResponse
    {
        $config = TicketConfig::where('is_active', true)->first();

        if (!$config) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_TICKET_NO_ACTIVE_CONFIG',
                'message' => 'No hay una configuracion de ticket activa.',
                'errors' => null,
                'metadata' => null,
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'data' => $config,
        ]);
    }

    public function show(TicketConfig $ticketConfig): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $ticketConfig->load('updatedByUser'),
        ]);
    }

    public function store(StoreTicketConfigRequest $request): JsonResponse
    {
        $config = DB::transaction(function () use ($request) {
            TicketConfig::where('is_active', true)->update(['is_active' => false]);

            $latestVersion = TicketConfig::max('version') ?? 0;

            return TicketConfig::create([
                'version' => $latestVersion + 1,
                'is_active' => true,
                'business_name' => $request->business_name,
                'rfc' => $request->rfc,
                'address' => $request->address,
                'phone' => $request->phone,
                'header_message' => $request->header_message,
                'footer_message' => $request->footer_message,
                'logo_url' => $request->logo_url,
                'updated_by' => $request->user()->id,
            ]);
        });

        return response()->json([
            'status' => 'success',
            'data' => $config,
            'metadata' => ['message' => 'Nueva version de ticket creada (v' . $config->version . ').'],
        ], 201);
    }
}
