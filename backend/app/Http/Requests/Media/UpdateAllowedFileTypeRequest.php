<?php

namespace App\Http\Requests\Media;

use App\Models\AllowedFileType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAllowedFileTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

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
        /*
         * The route model is bound as `allowed_file_type`; its id is excluded
         * from the unique rule so saving a row without touching its extension
         * does not fail against itself.
         */
        $current = $this->route('allowed_file_type');

        return [
            'extension' => [
                'sometimes',
                'string',
                'max:20',
                'regex:/^[a-z0-9]+$/',
                Rule::unique('allowed_file_types', 'extension')->ignore($current?->id),
            ],
            'mime_type' => ['sometimes', 'string', 'max:150', 'regex:#^[-\w.+]+/[-\w.+]+$#'],
            'label' => ['sometimes', 'string', 'max:100'],
            'max_size_kb' => ['sometimes', 'integer', 'min:1', 'max:'.(int) config('media.max_upload_kb', 25600)],
            'category' => ['sometimes', Rule::in(array_keys(AllowedFileType::CATEGORIES))],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'extension.unique' => 'Esa extensión ya pertenece a otra política.',
            'extension.regex' => 'La extensión solo admite letras y números, sin punto (ej. pdf, xlsx, png).',
            'mime_type.regex' => 'El MIME type debe tener la forma tipo/subtipo (ej. application/pdf).',
        ];
    }
}
