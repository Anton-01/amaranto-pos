<?php

namespace App\Http\Requests\TableSession;

use Illuminate\Foundation\Http\FormRequest;

class OpenTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'guests' => 'nullable|integer|min:1|max:500',
            'notes' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'guests.min' => 'El numero de comensales debe ser de al menos 1.',
        ];
    }
}
