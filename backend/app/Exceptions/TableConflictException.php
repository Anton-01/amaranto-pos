<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * Conflicto de negocio detectado dentro de una transaccion del comedor.
 *
 * Lanzarla aborta el bloque DB::transaction sin dejar escrituras a medias, y
 * el controlador la traduce al catalogo de errores corporativo homogeneo.
 */
class TableConflictException extends \RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function toResponse(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->httpStatus);
    }
}
