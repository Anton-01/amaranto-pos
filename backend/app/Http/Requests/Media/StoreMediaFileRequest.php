<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class StoreMediaFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Deliberately thin.
     *
     * The rules here only assert that SOMETHING was uploaded and that it fits
     * the platform's hard ceiling — two facts about the request, not about the
     * policy. Which extensions are acceptable, with which MIME and up to which
     * size, is decided by App\Services\Media\FileTypeValidator against the
     * `allowed_file_types` table.
     *
     * Duplicating the whitelist as `mimes:` rules here would create a second
     * source of truth that drifts the moment an administrator enables a new
     * type, and the drift would fail closed with a message pointing at nothing
     * the administrator can edit.
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) config('media.max_upload_kb', 25600)],
            'name' => ['nullable', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Selecciona un archivo para subir.',
            'file.max' => 'El archivo supera el tamaño máximo permitido por la plataforma ('
                .round(((int) config('media.max_upload_kb', 25600)) / 1024, 1).' MB).',
        ];
    }
}
