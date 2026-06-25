<?php

namespace App\DTOs;

final readonly class TicketDTO
{
    /**
     * @param array<TicketItemDTO> $items
     * @param array<string> $legends
     */
    public function __construct(
        public string $empresa,
        public string $rfc,
        public string $direccion,
        public string $telefono,
        public string $mensajeCabecera,
        public string $folio,
        public string $fechaHora,
        public string $metodoPago,
        public string $operador,
        public array $items,
        public string $subtotalNeto,
        public string $ivaMonto,
        public string $ivaLabel,
        public string $totalPublico,
        public ?string $recibido,
        public ?string $cambio,
        public ?string $leyendaPersonalizada,
        public string $mensajePie,
        public int $version,
        public string $qrContent,
        public array $legends = [],
    ) {}
}
