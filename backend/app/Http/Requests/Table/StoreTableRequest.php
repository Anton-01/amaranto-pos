<?php

namespace App\Http\Requests\Table;

use App\Models\Table;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:60|unique:tables,name',
            'capacity' => 'required|integer|min:1|max:100',
            'zone' => 'nullable|string|max:60',
            'status' => ['sometimes', 'string', Rule::in(Table::STATUSES)],
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'El nombre o numero de la mesa es obligatorio.',
            'name.unique' => 'Ya existe una mesa con ese nombre.',
            'capacity.required' => 'La capacidad de comensales es obligatoria.',
            'capacity.min' => 'La capacidad debe ser de al menos 1 comensal.',
        ];
    }
}
