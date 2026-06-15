<?php

namespace App\Http\Requests\PettyCash;

use Illuminate\Foundation\Http\FormRequest;

class StorePettyCashRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01|max:9999999999.99',
            'reason' => 'required|string|in:provider_payment,supplies_purchase,change_delivery,emergency',
            'description' => 'required|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'El monto debe ser mayor a $0.00.',
            'reason.in' => 'El motivo seleccionado no es valido.',
            'description.required' => 'La descripcion del retiro es obligatoria.',
        ];
    }
}
