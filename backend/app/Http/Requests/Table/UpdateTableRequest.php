<?php

namespace App\Http\Requests\Table;

use App\Models\Table;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tableId = $this->route('table')?->id;

        return [
            'name' => ['sometimes', 'string', 'max:60', Rule::unique('tables', 'name')->ignore($tableId)],
            'capacity' => 'sometimes|integer|min:1|max:100',
            'zone' => 'nullable|string|max:60',
            'status' => ['sometimes', 'string', Rule::in(Table::STATUSES)],
            'is_active' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe una mesa con ese nombre.',
            'capacity.min' => 'La capacidad debe ser de al menos 1 comensal.',
        ];
    }
}
