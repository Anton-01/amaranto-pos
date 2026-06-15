<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PragmaRX\Google2FALaravel\Google2FA;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

class TwoFactorController extends Controller
{
    public function setup(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->two_factor_confirmed_at) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_2FA_ALREADY_ENABLED',
                'message' => 'La autenticación de dos factores ya está activa.',
                'errors' => [],
                'metadata' => null,
            ], 409);
        }

        $google2fa = app(Google2FA::class);
        $secret = $google2fa->generateSecretKey();

        $user->update(['two_factor_secret' => $secret]);

        $otpauthUrl = $google2fa->getQRCodeUrl(
            'Cronos POS',
            $user->email,
            $secret
        );

        $renderer = new ImageRenderer(
            new RendererStyle(300),
            new SvgImageBackEnd()
        );
        $writer = new Writer($renderer);
        $svg = $writer->writeString($otpauthUrl);
        $qrBase64 = base64_encode($svg);

        return response()->json([
            'status' => 'success',
            'code' => '2FA_SETUP_INITIATED',
            'message' => 'Escanea el código QR con tu app de autenticación.',
            'data' => [
                'secret' => $secret,
                'qr_code' => "data:image/svg+xml;base64,{$qrBase64}",
            ],
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if (! $user->two_factor_secret) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_2FA_NOT_INITIATED',
                'message' => 'Primero debes iniciar la configuración del 2FA.',
                'errors' => [],
                'metadata' => null,
            ], 400);
        }

        if ($user->two_factor_confirmed_at) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_2FA_ALREADY_ENABLED',
                'message' => 'La autenticación de dos factores ya está confirmada.',
                'errors' => [],
                'metadata' => null,
            ], 409);
        }

        $google2fa = app(Google2FA::class);
        $valid = $google2fa->verifyKey($user->two_factor_secret, $request->code);

        if (! $valid) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_AUTH_INVALID_2FA_CODE',
                'message' => 'El código de verificación es incorrecto.',
                'errors' => [],
                'metadata' => null,
            ], 401);
        }

        $recoveryCodes = collect(range(1, 8))->map(fn () => bin2hex(random_bytes(5)))->toArray();

        $user->update([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes)),
        ]);

        return response()->json([
            'status' => 'success',
            'code' => '2FA_CONFIRMED',
            'message' => 'Autenticación de dos factores activada exitosamente.',
            'data' => [
                'recovery_codes' => $recoveryCodes,
            ],
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|size:6',
        ]);

        $user = $request->user();

        if (! $user->two_factor_confirmed_at) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_2FA_NOT_ENABLED',
                'message' => 'La autenticación de dos factores no está activa.',
                'errors' => [],
                'metadata' => null,
            ], 400);
        }

        $google2fa = app(Google2FA::class);
        $valid = $google2fa->verifyKey($user->two_factor_secret, $request->code);

        if (! $valid) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_AUTH_INVALID_2FA_CODE',
                'message' => 'El código de verificación es incorrecto.',
                'errors' => [],
                'metadata' => null,
            ], 401);
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ]);

        return response()->json([
            'status' => 'success',
            'code' => '2FA_DISABLED',
            'message' => 'Autenticación de dos factores desactivada.',
        ]);
    }
}
