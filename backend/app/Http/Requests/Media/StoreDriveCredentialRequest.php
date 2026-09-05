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
             * The OAuth triplet. The client id is not a secret and always comes
             * back to the panel, so it is required outright.
             */
            'client_id' => ['required', 'string', 'max:255'],

            /*
             * Nullable on purpose, both of them. The API never returns a stored
             * secret, so an administrator editing the folder id or the reader
             * list submits these fields empty; empty means "keep the value you
             * already have", exactly as the mailing panel treats its API key.
             * Rotating requires actually pasting a new value, which prevents an
             * accidental save from wiping a working connection.
             */
            'client_secret' => ['nullable', 'string', 'max:255'],

            /*
             * Google's refresh tokens are around 100 characters today, but the
             * length is not contractual and has grown before; the ceiling is
             * generous so a longer token is never rejected by this application
             * for a limit Google never promised to respect.
             */
            'refresh_token' => ['nullable', 'string', 'max:2048'],

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
            'client_id.required' => 'Indica el Client ID de OAuth generado en Google Cloud Console.',
            'root_folder_id.required' => 'Indica el ID de la carpeta raíz de Drive donde vivirá la biblioteca.',
            'root_folder_id.regex' => 'Pega solo el ID de la carpeta, no la URL completa. '
                .'Es el tramo que sigue a /folders/ en la dirección de Drive.',
            'authorized_emails.*.email' => 'Cada cuenta autorizada debe ser un correo válido.',
        ];
    }
}
