<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class QzSecurityController extends Controller
{
    public function sign(Request $request): Response
    {
        $request->validate([
            'requestString' => 'required|string',
        ]);

        $keyPath = storage_path('app/certs/private-key.pem');

        if (! file_exists($keyPath)) {
            return response('ERR_QZ_NO_PRIVATE_KEY', 500)
                ->header('Content-Type', 'text/plain');
        }

        $privateKey = file_get_contents($keyPath);
        $pkeyResource = openssl_pkey_get_private($privateKey);

        if (! $pkeyResource) {
            return response('ERR_QZ_INVALID_KEY', 500)
                ->header('Content-Type', 'text/plain');
        }

        openssl_sign($request->input('requestString'), $signature, $pkeyResource, OPENSSL_ALGO_SHA512);

        return response(base64_encode($signature), 200)
            ->header('Content-Type', 'text/plain');
    }

    public function certificate(): Response
    {
        $certPath = storage_path('app/certs/digital-certificate.txt');

        if (! file_exists($certPath)) {
            return response('ERR_QZ_NO_CERTIFICATE', 500)
                ->header('Content-Type', 'text/plain');
        }

        return response(file_get_contents($certPath), 200)
            ->header('Content-Type', 'text/plain');
    }
}
