<?php

namespace App\Http\Requests\StockMovement;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => 'required|uuid|exists:products,id',
            'type' => 'required|string|in:purchase_input,merma_output,adjustment',
            'quantity' => 'required|integer|min:1',
            'cost_price_at_movement' => 'required|numeric|min:0|max:9999999999.99',
            'reason' => $this->requiresReason() ? 'required|string|in:expired,damaged_spilled,internal_consumption,theft_loss' : 'nullable|string|in:expired,damaged_spilled,internal_consumption,theft_loss',
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'El motivo de merma es obligatorio para movimientos de tipo merma.',
            'reason.in' => 'El motivo debe ser uno de: expired, damaged_spilled, internal_consumption, theft_loss.',
        ];
    }

    private function requiresReason(): bool
    {
        return $this->input('type') === 'merma_output';
    }
}
