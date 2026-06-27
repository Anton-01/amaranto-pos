<?php

namespace App\Http\Requests\Order;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
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
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|uuid|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.promotion_id' => 'nullable|uuid|exists:promotions,id',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $items = $this->input('items', []);

            $promotionIds = collect($items)
                ->pluck('promotion_id')
                ->filter()
                ->unique()
                ->values();

            if ($promotionIds->count() > 1) {
                $validator->errors()->add('items', 'Solo se permite un maximo de 1 promocion por ticket de compra.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'items.required' => 'El ticket debe contener al menos un producto.',
            'items.min' => 'El ticket debe contener al menos un producto.',
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $errors = $validator->errors();

        if ($errors->has('items') && str_contains($errors->first('items'), 'promocion')) {
            throw new \Illuminate\Validation\ValidationException($validator, response()->json([
                'status' => 'error',
                'code' => 'ERR_POS_PROMOTION_LIMIT_EXCEEDED',
                'message' => 'Solo se permite aplicar 1 promocion o cupon por ticket de compra.',
                'errors' => $errors->toArray(),
                'metadata' => null,
            ], 422));
        }

        parent::failedValidation($validator);
    }
}
