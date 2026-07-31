<?php

namespace App\Services;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use Illuminate\Support\Collection;

/**
 * Motor unico de calculo monetario de una orden (tax-inclusive).
 *
 * Existe para que el mostrador (OrderController::store) y el comedor
 * (TableSessionController) no mantengan dos versiones de la misma aritmetica:
 * un centavo de divergencia entre ambos flujos rompe el arqueo de caja.
 *
 * Convencion de la casa: los precios de catalogo YA incluyen IVA, de modo que
 * el neto y el impuesto se despejan del total (`total / (1 + tax)`), nunca al
 * reves.
 */
class OrderCalculator
{
    /**
     * Descuento que una promocion aplica sobre el bruto de una linea.
     */
    public function promotionDiscount(Promotion $promotion, float $lineGross): float
    {
        return match ($promotion->type) {
            'percentage' => $lineGross * ((float) $promotion->value / 100),
            'fixed_amount' => min((float) $promotion->value, $lineGross),
            'freebie_100' => $lineGross,
            default => 0.0,
        };
    }

    /**
     * Construye la linea cruda de un producto con su promocion opcional.
     *
     * @return array{product_id:string, promotion_id:?string, quantity:int, line_gross:float, item_discount:float}
     */
    public function line(Product $product, ?Promotion $promotion, int $quantity): array
    {
        $lineGross = (float) $product->sale_price * $quantity;

        return [
            'product_id' => $product->id,
            'promotion_id' => $promotion?->id,
            'quantity' => $quantity,
            'line_gross' => $lineGross,
            'item_discount' => $promotion ? $this->promotionDiscount($promotion, $lineGross) : 0.0,
        ];
    }

    /**
     * Reconstruye las lineas crudas a partir de items ya persistidos.
     *
     * `line_gross = final_price_at_sale + discount_amount_at_sale` es exacto por
     * construccion de compose(). La reconstruccion solo es fiel mientras la
     * orden no arrastre un descuento global previo (`discount_type = 'none'`),
     * que es justo la invariante de una cuenta de mesa abierta: el descuento se
     * aplica una sola vez, en el cobro.
     *
     * @param  Collection<int, OrderItem>  $items
     * @return array<int, array<string, mixed>>
     */
    public function linesFromOrderItems(Collection $items): array
    {
        return $items->map(fn (OrderItem $item) => [
            'ref' => $item->id,
            'product_id' => $item->product_id,
            'promotion_id' => $item->promotion_id,
            'quantity' => (int) $item->quantity,
            'line_gross' => (float) $item->final_price_at_sale + (float) $item->discount_amount_at_sale,
            'item_discount' => (float) $item->discount_amount_at_sale,
        ])->values()->all();
    }

    /**
     * Reparte el descuento global entre las lineas y despeja subtotal e IVA.
     *
     * El descuento global se prorratea proporcionalmente al peso de cada linea
     * sobre el bruto post-promocion, de modo que la suma de los items siempre
     * cuadra contra el total de la orden.
     *
     * Cada linea de entrada admite una clave opcional `ref` que se devuelve
     * intacta en la salida, para poder actualizar los items existentes sin
     * borrarlos ni perder su `created_at` (la trazabilidad de la comanda).
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{items: array<int, array<string, mixed>>, subtotal: float, iva_total: float, total: float, discount_total: float}
     */
    public function compose(array $lines, float $taxRate, string $discountType = 'none', float $discountValue = 0.0): array
    {
        $totalGross = 0.0;

        foreach ($lines as $index => $line) {
            $finalLineTotal = $line['line_gross'] - $line['item_discount'];
            $lines[$index]['final_line_total'] = $finalLineTotal;
            $totalGross += $finalLineTotal;
        }

        $discountTotal = 0.0;

        if ($discountType === 'fixed' && $discountValue > 0) {
            $discountTotal = min($discountValue, $totalGross);
        } elseif ($discountType === 'percentage' && $discountValue > 0) {
            $discountTotal = min(round($totalGross * ($discountValue / 100), 2), $totalGross);
        }

        $totalAfterDiscount = round($totalGross - $discountTotal, 2);
        $subtotal = round($totalAfterDiscount / (1 + $taxRate), 2);
        $ivaTotal = round($totalAfterDiscount - $subtotal, 2);

        $items = [];

        foreach ($lines as $line) {
            $proportion = $totalGross > 0 ? $line['final_line_total'] / $totalGross : 0;
            $itemDiscountShare = round($discountTotal * $proportion, 2);
            $adjustedFinal = $line['final_line_total'] - $itemDiscountShare;
            $netPrice = $adjustedFinal / (1 + $taxRate);
            $tax = $adjustedFinal - $netPrice;

            $composed = [
                'product_id' => $line['product_id'],
                'promotion_id' => $line['promotion_id'],
                'quantity' => $line['quantity'],
                'base_price_at_sale' => round($netPrice / $line['quantity'], 2),
                'discount_amount_at_sale' => round($line['item_discount'] + $itemDiscountShare, 2),
                'final_price_at_sale' => round($adjustedFinal, 2),
                'tax_amount_at_sale' => round($tax, 2),
            ];

            if (isset($line['ref'])) {
                $composed['ref'] = $line['ref'];
            }

            $items[] = $composed;
        }

        return [
            'items' => $items,
            'subtotal' => $subtotal,
            'iva_total' => $ivaTotal,
            'total' => $totalAfterDiscount,
            'discount_total' => round($discountTotal, 2),
        ];
    }
}
