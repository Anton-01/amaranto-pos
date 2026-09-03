<?php

namespace App\Http\Requests\Media;

use App\Models\MediaShareLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMediaShareLinkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /*
             * Required, and drawn from a closed list. There is no "never
             * expires" option, and its absence is the security decision: an
             * eternal link is indistinguishable from a public file, which is
             * precisely what this module refuses to create.
             */
            'expires_in_hours' => [
                'required',
                'integer',
                Rule::in(config('media.share_links.expiration_options', [24])),
            ],

            'permission' => ['required', Rule::in(array_keys(MediaShareLink::PERMISSIONS))],

            // Null means "unlimited within the window". The cap is a second,
            // independent brake for the cases where even a short window is too
            // much exposure.
            'max_downloads' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.(int) config('media.share_links.max_download_limit', 1000),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'expires_in_hours.in' => 'Selecciona una de las ventanas de expiración disponibles.',
            'permission.in' => 'El nivel de acceso del enlace no es válido.',
        ];
    }
}
