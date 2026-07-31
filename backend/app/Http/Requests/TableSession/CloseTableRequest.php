<?php

namespace App\Http\Requests\TableSession;

use Illuminate\Foundation\Http\FormRequest;

class CloseTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'payment_method_id' => 'required|uuid|exists:payment_methods,id',
            'custom_legend' => 'nullable|string|max:500',
            'amount_received' => 'nullable|numeric|min:0|max:9999999999.99',
            'amount_change' => 'nullable|numeric|min:0|max:9999999999.99',
            'discount_type' => 'nullable|string|in:fixed,percentage,none',
            'discount_value' => 'nullable|numeric|min:0|max:9999999999.99',
            'promotion_id' => 'nullable|uuid|exists:promotions,id',
        ];
    }

    public function messages(): array
    {
        return [
            'payment_method_id.required' => 'Selecciona un metodo de pago para cerrar la cuenta.',
        ];
    }
}
