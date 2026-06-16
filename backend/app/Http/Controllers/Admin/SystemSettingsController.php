<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlobalSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemSettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $settings = GlobalSetting::all()->keyBy('key')->map(fn ($s) => $s->value);

        return response()->json([
            'status' => 'success',
            'data' => $settings,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string|max:100',
            'settings.*.value' => 'required|array',
        ]);

        DB::transaction(function () use ($request) {
            foreach ($request->input('settings') as $setting) {
                GlobalSetting::updateOrCreate(
                    ['key' => $setting['key']],
                    [
                        'value' => $setting['value'],
                        'updated_by' => $request->user()->id,
                    ]
                );
            }
        });

        $settings = GlobalSetting::all()->keyBy('key')->map(fn ($s) => $s->value);

        return response()->json([
            'status' => 'success',
            'data' => $settings,
            'metadata' => ['message' => 'Configuración actualizada correctamente.'],
        ]);
    }
}
