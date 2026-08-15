<?php

namespace App\Http\Requests\CacheConfiguration;

use App\Models\CacheConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCacheConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `module_name` is intentionally absent: a policy row is bound to
            // its module for life. Pointing an existing row at another module
            // would silently orphan the payloads already stored under the old
            // one, so the UI creates a new row instead.
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
            'duration_minutes.in' => 'Selecciona uno de los tiempos de refresco disponibles.',
        ];
    }
}
