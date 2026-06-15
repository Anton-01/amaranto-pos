<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('products')->ignore($this->route('product'))],
            'parent_sku' => 'nullable|string|max:50',
            'name' => 'sometimes|required|string|max:150',
            'category_id' => 'sometimes|required|uuid|exists:categories,id',
            'cost_price' => 'sometimes|required|numeric|min:0|max:9999999999.99',
            'sale_price' => 'sometimes|required|numeric|min:0|max:9999999999.99',
            'current_stock' => 'sometimes|integer|min:0',
            'minimum_stock' => 'sometimes|integer|min:0',
            'maximum_stock' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
