<?php

namespace App\Builders;

use App\DTOs\TicketDTO;
use App\DTOs\TicketItemDTO;
use App\Models\GlobalSetting;
use App\Models\Order;
use App\Models\TicketConfig;

class TicketBuilder
{
    public const LINE_WIDTH = 32;

    public function build(Order $order, TicketConfig $ticketConfig): TicketDTO
    {
        $order->load(['items.product', 'paymentMethod', 'cashRegister.user']);

        $taxSetting = GlobalSetting::where('key', 'tax_rate')->first();
        $taxRate = $taxSetting ? (float) ($taxSetting->value['rate'] ?? 0.16) : 0.16;
        $ivaLabel = $taxSetting ? ($taxSetting->value['label'] ?? 'IVA 16%') : 'IVA 16%';

        $items = [];
        foreach ($order->items as $item) {
            $productName = $item->product?->name ?? 'Producto eliminado';
            $qty = $item->quantity;
            $finalPrice = (float) $item->final_price_at_sale;

            $items[] = new TicketItemDTO(
                nombreVariacion: $productName,
                cantidad: $qty,
                precioFinal: $this->formatMoney($finalPrice / $qty),
                totalItem: $this->formatMoney($finalPrice),
            );
        }

        $operador = $order->cashRegister?->user?->name ?? 'N/A';
        $metodoPago = $order->paymentMethod?->name ?? 'N/A';
        $folio = strtoupper(substr($order->id, 0, 8));

        $recibido = null;
        $cambio = null;
        if ($order->amount_received && (float) $order->amount_received > 0) {
            $recibido = $this->formatMoney((float) $order->amount_received);
            $cambio = $this->formatMoney((float) $order->amount_change);
        }

        $qrContent = config('app.url') . '/orders/' . $order->id;

        return new TicketDTO(
            empresa: $ticketConfig->business_name ?? '',
            rfc: $ticketConfig->rfc ?? '',
            direccion: $ticketConfig->address ?? '',
            telefono: $ticketConfig->phone ?? '',
            mensajeCabecera: $ticketConfig->header_message ?? '',
            folio: $folio,
            fechaHora: $order->created_at->format('d/m/Y H:i:s'),
            metodoPago: $metodoPago,
            operador: $operador,
            items: $items,
            subtotalNeto: $this->formatMoney((float) $order->subtotal),
            ivaMonto: $this->formatMoney((float) $order->iva_total),
            ivaLabel: $ivaLabel,
            totalPublico: $this->formatMoney((float) $order->total),
            recibido: $recibido,
            cambio: $cambio,
            leyendaPersonalizada: $order->custom_legend,
            mensajePie: $ticketConfig->footer_message ?? '',
            version: $ticketConfig->version,
            qrContent: $qrContent,
        );
    }

    public function formatMoney(float $amount): string
    {
        return '$' . number_format($amount, 2, '.', ',');
    }

    /**
     * Pad a left-right aligned line to exactly LINE_WIDTH characters.
     * If the combined length exceeds LINE_WIDTH, the left text is truncated.
     */
    public function padLine(string $left, string $right): string
    {
        $rightLen = mb_strlen($right);
        $maxLeft = self::LINE_WIDTH - $rightLen - 1;

        if (mb_strlen($left) > $maxLeft) {
            $left = mb_substr($left, 0, $maxLeft - 2) . '..';
        }

        $spaces = self::LINE_WIDTH - mb_strlen($left) - $rightLen;

        return $left . str_repeat(' ', max($spaces, 1)) . $right;
    }

    /**
     * Format a product line for the ticket (quantity x product ... total).
     * Returns an array of lines if the product name is too long.
     */
    public function formatProductLine(TicketItemDTO $item): array
    {
        $prefix = $item->cantidad . ' x ';
        $total = $item->totalItem;
        $totalLen = mb_strlen($total);
        $prefixLen = mb_strlen($prefix);

        $availableForName = self::LINE_WIDTH - $prefixLen - $totalLen - 1;

        if ($availableForName < 4) {
            $availableForName = 4;
        }

        $name = $item->nombreVariacion;

        if (mb_strlen($name) <= $availableForName) {
            $line = $prefix . $name;
            $spaces = self::LINE_WIDTH - mb_strlen($line) - $totalLen;

            return [$line . str_repeat(' ', max($spaces, 1)) . $total];
        }

        $truncated = mb_substr($name, 0, $availableForName - 2) . '..';
        $line = $prefix . $truncated;
        $spaces = self::LINE_WIDTH - mb_strlen($line) - $totalLen;

        return [$line . str_repeat(' ', max($spaces, 1)) . $total];
    }

    public function separator(string $char = '-'): string
    {
        return str_repeat($char, self::LINE_WIDTH);
    }

    public function centerText(string $text): string
    {
        $textLen = mb_strlen($text);
        if ($textLen >= self::LINE_WIDTH) {
            return mb_substr($text, 0, self::LINE_WIDTH);
        }

        $padding = intdiv(self::LINE_WIDTH - $textLen, 2);

        return str_repeat(' ', $padding) . $text;
    }

    /**
     * Build the full plain-text representation of the ticket.
     * Each line is guaranteed to be <= LINE_WIDTH characters.
     */
    public function buildTextLines(TicketDTO $dto): array
    {
        $lines = [];

        $lines[] = $this->centerText($dto->empresa);
        if ($dto->rfc) {
            $lines[] = $this->centerText('RFC: ' . $dto->rfc);
        }
        if ($dto->direccion) {
            foreach ($this->wrapText($dto->direccion, self::LINE_WIDTH) as $wl) {
                $lines[] = $this->centerText($wl);
            }
        }
        if ($dto->telefono) {
            $lines[] = $this->centerText('Tel: ' . $dto->telefono);
        }
        if ($dto->mensajeCabecera) {
            $lines[] = '';
            foreach ($this->wrapText($dto->mensajeCabecera, self::LINE_WIDTH) as $wl) {
                $lines[] = $this->centerText($wl);
            }
        }

        $lines[] = $this->separator('=');

        $lines[] = $this->padLine('Folio:', $dto->folio);
        $lines[] = $this->padLine('Fecha:', $dto->fechaHora);
        $lines[] = $this->padLine('Pago:', $dto->metodoPago);
        $lines[] = $this->padLine('Operador:', $dto->operador);

        $lines[] = $this->separator('=');
        $lines[] = $this->padLine('PRODUCTO', 'IMPORTE');
        $lines[] = $this->separator('-');

        foreach ($dto->items as $item) {
            foreach ($this->formatProductLine($item) as $productLine) {
                $lines[] = $productLine;
            }
        }

        $lines[] = $this->separator('-');
        $lines[] = $this->padLine('Subtotal:', $dto->subtotalNeto);
        $lines[] = $this->padLine($dto->ivaLabel . ':', $dto->ivaMonto);
        $lines[] = $this->separator('=');
        $lines[] = $this->padLine('TOTAL:', $dto->totalPublico);

        if ($dto->recibido) {
            $lines[] = $this->separator('-');
            $lines[] = $this->padLine('Recibido:', $dto->recibido);
            $lines[] = $this->padLine('Cambio:', $dto->cambio ?? '$0.00');
        }

        if ($dto->leyendaPersonalizada) {
            $lines[] = '';
            foreach ($this->wrapText($dto->leyendaPersonalizada, self::LINE_WIDTH) as $wl) {
                $lines[] = $wl;
            }
        }

        if ($dto->mensajePie) {
            $lines[] = '';
            foreach ($this->wrapText($dto->mensajePie, self::LINE_WIDTH) as $wl) {
                $lines[] = $this->centerText($wl);
            }
        }

        $lines[] = '';
        $lines[] = $this->centerText('v' . $dto->version);

        return $lines;
    }

    /**
     * Wrap text to fit within maxWidth characters per line.
     */
    public function wrapText(string $text, int $maxWidth): array
    {
        if (mb_strlen($text) <= $maxWidth) {
            return [$text];
        }

        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if ($current === '') {
                $current = $word;
            } elseif (mb_strlen($current . ' ' . $word) <= $maxWidth) {
                $current .= ' ' . $word;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }
}
