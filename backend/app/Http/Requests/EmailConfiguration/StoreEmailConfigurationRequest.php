<?php

namespace App\Http\Requests\EmailConfiguration;

use App\Models\EmailConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmailConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // One row per process: the unique rule mirrors the database
            // constraint so the user gets a field error instead of a 500.
            'process_type' => [
                'required',
                'string',
                Rule::in(array_keys(EmailConfiguration::PROCESS_TYPES)),
                Rule::unique('email_configurations', 'process_type'),
            ],
            'provider' => ['required', 'string', Rule::in(array_keys(EmailConfiguration::PROVIDERS))],
            'api_key' => ['required', 'string', 'max:500'],
            'from_email' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:120'],
            'target_emails' => ['required', 'array', 'min:1', 'max:20'],
            'target_emails.*' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'process_type.unique' => 'Ya existe una configuracion para este tipo de proceso. Editala en lugar de crear otra.',
            'target_emails.required' => 'Agrega al menos un correo destino.',
            'target_emails.max' => 'Puedes configurar un maximo de 20 destinatarios.',
            'target_emails.*.email' => 'Uno o mas correos destino no tienen un formato valido.',
            'api_key.required' => 'La API Key del proveedor es obligatoria.',
        ];
    }
}
