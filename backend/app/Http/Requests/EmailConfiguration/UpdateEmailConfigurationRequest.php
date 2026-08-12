<?php

namespace App\Http\Requests\EmailConfiguration;

use App\Models\EmailConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmailConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $current = $this->route('email_configuration');

        return [
            'process_type' => [
                'sometimes',
                'string',
                Rule::in(array_keys(EmailConfiguration::PROCESS_TYPES)),
                Rule::unique('email_configurations', 'process_type')->ignore($current?->id),
            ],
            'provider' => ['sometimes', 'string', Rule::in(array_keys(EmailConfiguration::PROVIDERS))],
            // Optional on update: the browser never receives the stored key, so
            // an empty field means "keep the current credential", not "clear it".
            'api_key' => ['nullable', 'string', 'max:500'],
            'from_email' => ['sometimes', 'email', 'max:255'],
            'from_name' => ['sometimes', 'string', 'max:120'],
            'target_emails' => ['sometimes', 'array', 'min:1', 'max:20'],
            'target_emails.*' => ['required', 'email'],
            'subject' => ['sometimes', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'process_type.unique' => 'Ya existe una configuracion para este tipo de proceso.',
            'target_emails.min' => 'Agrega al menos un correo destino.',
            'target_emails.max' => 'Puedes configurar un maximo de 20 destinatarios.',
            'target_emails.*.email' => 'Uno o mas correos destino no tienen un formato valido.',
        ];
    }
}
