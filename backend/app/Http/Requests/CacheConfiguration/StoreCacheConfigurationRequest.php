<?php

namespace App\Http\Requests\CacheConfiguration;

use App\Models\CacheConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCacheConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // One policy per module: the unique rule mirrors the database
            // constraint so the user gets a field error instead of a 500.
            'module_name' => [
                'required',
                'string',
                Rule::in(array_keys(CacheConfiguration::MODULES)),
                Rule::unique('cache_configurations', 'module_name'),
            ],
            // Closed list of windows: an arbitrary TTL typed by hand is how a
            // dashboard ends up frozen for a week.
            'duration_minutes' => [
                'required',
                'integer',
                Rule::in(array_keys(CacheConfiguration::DURATION_OPTIONS)),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'module_name.unique' => 'Ya existe una politica de cache para este modulo. Editala en lugar de crear otra.',
            'module_name.in' => 'El modulo seleccionado no admite cache dinamico.',
            'duration_minutes.in' => 'Selecciona uno de los tiempos de refresco disponibles.',
        ];
    }
}
