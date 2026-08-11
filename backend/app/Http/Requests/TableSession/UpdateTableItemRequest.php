<?php

namespace App\Http\Requests\TableSession;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTableItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Minimum of one unit: dropping to zero is a removal, and that goes
            // through DELETE so it lands in the audit trail as such.
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.min' => 'La cantidad minima es 1. Para retirar la partida usa el boton de eliminar.',
        ];
    }
}
