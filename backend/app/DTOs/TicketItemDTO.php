<?php

namespace App\DTOs;

final readonly class TicketItemDTO
{
    public function __construct(
        public string $nombreVariacion,
        public int $cantidad,
        public string $precioFinal,
        public string $totalItem,
    ) {}
}
