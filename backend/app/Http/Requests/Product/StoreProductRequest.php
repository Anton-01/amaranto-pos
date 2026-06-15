<?php

namespace App\Http\Requests\Product;

use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => 'required|string|max:50|unique:products,sku',
            'parent_sku' => 'nullable|string|max:50',
            'name' => 'required|string|max:150',
            'category_id' => 'required|uuid|exists:categories,id',
            'cost_price' => 'required|numeric|min:0|max:9999999999.99',
            'sale_price' => 'required|numeric|min:0|max:9999999999.99',
            'current_stock' => 'integer|min:0',
            'minimum_stock' => 'integer|min:0',
            'maximum_stock' => 'integer|min:0',
            'is_active' => 'boolean',
        ];
    }
}
