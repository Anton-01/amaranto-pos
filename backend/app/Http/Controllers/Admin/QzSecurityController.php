<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QzSecurityController extends Controller
{
    public function sign(Request $request): JsonResponse
    {
        $request->validate([
            'requestString' => 'required|string',
        ]);

        $keyPath = storage_path('app/certs/private-key.pem');

        if (! file_exists($keyPath)) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_QZ_NO_PRIVATE_KEY',
                'message' => 'La llave privada de QZ Tray no está configurada.',
            ], 500);
        }

        $privateKey = file_get_contents($keyPath);
        $pkeyResource = openssl_pkey_get_private($privateKey);

        if (! $pkeyResource) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_QZ_INVALID_KEY',
                'message' => 'La llave privada de QZ Tray es inválida.',
            ], 500);
        }

        openssl_sign($request->input('requestString'), $signature, $pkeyResource, OPENSSL_ALGO_SHA512);

        return response()->json(base64_encode($signature));
    }

    public function certificate(): JsonResponse
    {
        $certPath = storage_path('app/certs/digital-certificate.txt');

        if (! file_exists($certPath)) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_QZ_NO_CERTIFICATE',
                'message' => 'El certificado digital de QZ Tray no está configurado.',
            ], 500);
        }

        return response()->json(file_get_contents($certPath));
    }
}
