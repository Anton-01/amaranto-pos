<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class TestDriveConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Everything is optional because the health check runs on what is TYPED in
     * the form, falling back to what is stored for the fields the form cannot
     * repopulate (the service account JSON never comes back to the browser).
     *
     * That is what lets an administrator validate a credential BEFORE
     * persisting it — the whole reason a synchronous test exists.
     */
    public function rules(): array
    {
        return [
            'credential_id' => ['nullable', 'uuid', 'exists:drive_credentials,id'],
            'service_account_json' => ['nullable', 'string', 'max:20000'],
            'root_folder_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9_-]+$/'],
            'authorized_emails' => ['sometimes', 'array', 'max:50'],
            'authorized_emails.*' => ['email'],
        ];
    }

    public function messages(): array
    {
        return [
            'root_folder_id.regex' => 'Pega solo el ID de la carpeta, no la URL completa de Drive.',
        ];
    }
}
