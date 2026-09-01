<?php

namespace App\Http\Requests\Media;

use App\Models\AllowedFileType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMediaFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the editable metadata of the WordPress-style modal.
     *
     * The immutable facts of the object — extension, mime type, size, checksum,
     * Drive id — are absent by design: they describe bytes that already exist,
     * and letting a form rewrite them would make the index disagree with the
     * storage while looking perfectly consistent.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'alt_text' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['sometimes', Rule::in(array_keys(AllowedFileType::CATEGORIES))],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
