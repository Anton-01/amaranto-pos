<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;

class DeleteCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'deletion_reason' => 'required|string|max:255',
        ];
    }
}
