<?php

namespace App\Http\Requests\Promotion;

use Illuminate\Foundation\Http\FormRequest;

class StorePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'type' => 'required|string|in:percentage,fixed_amount,freebie_100',
            'value' => 'required|numeric|min:0|max:9999999999.99',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'is_active' => 'boolean',
            'product_ids' => 'sometimes|array',
            'product_ids.*' => 'uuid|exists:products,id',
        ];
    }
}
