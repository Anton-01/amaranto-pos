<?php

namespace App\Http\Requests\EmailConfiguration;

use App\Models\EmailConfiguration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Payload of the mailing health check.
 *
 * It mirrors the store rules with one deliberate exception: `api_key` is
 * optional. The admin form blanks the credential field while editing an
 * existing row (typing is what rotates a key), so requiring it here would make
 * the button untestable on precisely the configurations already in production.
 * When it arrives empty, the controller falls back to the stored credential of
 * `configuration_id`.
 */
class TestEmailConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Nothing is persisted by this endpoint, so process_type is not
            // checked for uniqueness: it only labels the diagnostic message and
            // resolves the provider skeleton.
            'process_type' => ['required', 'string', Rule::in(array_keys(EmailConfiguration::PROCESS_TYPES))],
            'provider' => ['required', 'string', Rule::in(array_keys(EmailConfiguration::PROVIDERS))],
            'api_key' => ['nullable', 'string', 'max:500'],
            'configuration_id' => ['nullable', 'uuid', Rule::exists('email_configurations', 'id')],
            'from_email' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:120'],
            'target_emails' => ['required', 'array', 'min:1', 'max:20'],
            'target_emails.*' => ['required', 'email'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_emails.required' => 'Agrega al menos un correo destino para enviar la prueba.',
            'target_emails.*.email' => 'Uno o mas correos destino no tienen un formato valido.',
            'from_email.email' => 'El correo remitente no tiene un formato valido.',
            'configuration_id.exists' => 'La configuracion de referencia ya no existe. Recarga la pagina.',
        ];
    }
}
