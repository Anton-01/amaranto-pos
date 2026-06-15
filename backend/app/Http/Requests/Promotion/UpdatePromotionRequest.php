<?php

namespace App\Http\Requests\Promotion;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:100',
            'type' => 'sometimes|required|string|in:percentage,fixed_amount,freebie_100',
            'value' => 'sometimes|required|numeric|min:0|max:9999999999.99',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after:start_date',
            'is_active' => 'sometimes|boolean',
            'product_ids' => 'sometimes|array',
            'product_ids.*' => 'uuid|exists:products,id',
        ];
    }
}
