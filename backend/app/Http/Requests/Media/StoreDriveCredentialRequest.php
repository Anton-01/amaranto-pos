<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class StoreDriveCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:120'],

            /*
             * Nullable on purpose. The panel never returns the stored JSON, so
             * an administrator editing the folder id or the reader list submits
             * this field empty; an empty value means "keep the credential you
             * already have", exactly as the mailing panel treats its API key.
             * Rotating requires actually pasting a new document.
             */
            'service_account_json' => ['nullable', 'string', 'max:20000'],

            'client_id' => ['nullable', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:255'],

            /*
             * Drive ids are opaque strings from Google's alphabet. The pattern
             * exists so somebody pasting a whole folder URL gets a field error
             * naming the mistake, instead of a 404 from Google hours later.
             */
            'root_folder_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_-]+$/'],

            'authorized_emails' => ['sometimes', 'array', 'max:50'],
            'authorized_emails.*' => ['email'],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'root_folder_id.required' => 'Indica el ID de la carpeta raíz de Drive donde vivirá la biblioteca.',
            'root_folder_id.regex' => 'Pega solo el ID de la carpeta, no la URL completa. '
                .'Es el tramo que sigue a /folders/ en la dirección de Drive.',
            'authorized_emails.*.email' => 'Cada cuenta autorizada debe ser un correo válido.',
        ];
    }
}
