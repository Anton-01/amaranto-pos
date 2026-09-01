<?php

namespace App\Http\Requests\Media;

use App\Models\AllowedFileType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAllowedFileTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The extension arrives normalized so ".PDF", "PDF" and "pdf" collide on
     * the unique rule instead of creating three competing policies for the
     * same real type.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('extension')) {
            $this->merge([
                'extension' => AllowedFileType::normalizeExtension((string) $this->input('extension')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'extension' => [
                'required',
                'string',
                'max:20',
                // Letters and digits only: an extension carrying a slash or a
                // dot is either a mistake or an attempt to widen the match.
                'regex:/^[a-z0-9]+$/',
                Rule::unique('allowed_file_types', 'extension'),
            ],
            'mime_type' => ['required', 'string', 'max:150', 'regex:#^[-\w.+]+/[-\w.+]+$#'],
            'label' => ['required', 'string', 'max:100'],
            // The upper bound is the platform ceiling: a policy may be
            // stricter than the server's limits, never looser.
            'max_size_kb' => ['required', 'integer', 'min:1', 'max:'.(int) config('media.max_upload_kb', 25600)],
            'category' => ['required', Rule::in(array_keys(AllowedFileType::CATEGORIES))],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'extension.unique' => 'Esa extensión ya está registrada. Edita la política existente en lugar de crear otra.',
            'extension.regex' => 'La extensión solo admite letras y números, sin punto (ej. pdf, xlsx, png).',
            'mime_type.regex' => 'El MIME type debe tener la forma tipo/subtipo (ej. application/pdf).',
            'max_size_kb.max' => 'El límite no puede superar el techo de la plataforma ('
                .(int) config('media.max_upload_kb', 25600).' KB).',
            'category.in' => 'Selecciona una de las categorías disponibles.',
        ];
    }
}
