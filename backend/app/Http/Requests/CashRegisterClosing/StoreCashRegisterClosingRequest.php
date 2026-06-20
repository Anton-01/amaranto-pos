<?php

namespace App\Http\Requests\CashRegisterClosing;

use Illuminate\Foundation\Http\FormRequest;

class StoreCashRegisterClosingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'declarations'    => 'required|array',
            'declarations.*'  => 'required|numeric|min:0|max:9999999999.99',
        ];
    }

    public function messages(): array
    {
        return [
            'declarations.required'   => 'Debes declarar los montos por método de pago.',
            'declarations.array'      => 'El formato de declaraciones es inválido.',
            'declarations.*.numeric'  => 'Cada monto declarado debe ser un número.',
            'declarations.*.min'      => 'Los montos declarados no pueden ser negativos.',
        ];
    }
}
