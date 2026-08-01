<?php

namespace App\Http\Requests\TableSession;

use Illuminate\Foundation\Http\FormRequest;

class AddTableItemsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.promotion_id' => 'nullable|uuid|exists:promotions,id',
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Debes agregar al menos un producto a la comanda.',
            'items.min' => 'Debes agregar al menos un producto a la comanda.',
        ];
    }
}
