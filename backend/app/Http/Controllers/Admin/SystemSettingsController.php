<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GlobalSetting;
use App\Services\CancellationAuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SystemSettingsController extends Controller
{
    public function __construct(
        private readonly CancellationAuthorizationService $authorization,
    ) {}

    public function index(): JsonResponse
    {
        // Secrets are stripped before the payload leaves: the settings screen
        // asks whether they are configured, never what they are.
        $settings = GlobalSetting::all()
            ->reject(fn (GlobalSetting $s) => in_array($s->key, GlobalSetting::PROTECTED_KEYS, true))
            ->keyBy('key')
            ->map(fn ($s) => $s->value);

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

        /*
         * The generic endpoint stores values verbatim, so letting a protected
         * key through here would write a secret in clear text and bypass the
         * hashing its own endpoint performs.
         */
        foreach ($request->input('settings') as $index => $setting) {
            if (in_array($setting['key'] ?? null, GlobalSetting::PROTECTED_KEYS, true)) {
                throw ValidationException::withMessages([
                    "settings.{$index}.key" => 'Esta configuracion es sensible y solo puede modificarse desde su propio endpoint.',
                ]);
            }
        }

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

        $settings = GlobalSetting::all()
            ->reject(fn (GlobalSetting $s) => in_array($s->key, GlobalSetting::PROTECTED_KEYS, true))
            ->keyBy('key')
            ->map(fn ($s) => $s->value);

        return response()->json([
            'status' => 'success',
            'data' => $settings,
            'metadata' => ['message' => 'Configuración actualizada correctamente.'],
        ]);
    }

    /**
     * State of the cancellation authorization password.
     *
     * Reports only whether a secret exists and when it was last rotated — the
     * hash itself never leaves the server.
     */
    public function cancellationPassword(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'is_configured' => $this->authorization->isConfigured(),
                'updated_at' => $this->authorization->configuredAt(),
            ],
        ]);
    }

    /**
     * Sets or rotates the cancellation authorization password.
     *
     * Stored hashed and kept independent of every login credential on purpose:
     * it is a delegated authority a supervisor can hand to a shift lead without
     * surrendering their own account.
     */
    public function updateCancellationPassword(Request $request): JsonResponse
    {
        $request->validate([
            'password' => 'required|string|min:6|max:100|confirmed',
        ], [
            'password.confirmed' => 'La confirmacion no coincide con la contrasena.',
            'password.min' => 'La contrasena de autorizacion debe tener al menos 6 caracteres.',
        ]);

        $this->authorization->store($request->input('password'), $request->user()->id);

        return response()->json([
            'status' => 'success',
            'data' => [
                'is_configured' => true,
                'updated_at' => $this->authorization->configuredAt(),
            ],
            'metadata' => ['message' => 'Contraseña de autorización actualizada.'],
        ]);
    }
}
