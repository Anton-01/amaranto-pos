<?php

namespace App\Http\Requests\Social;

use App\Models\SocialAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSocialAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', Rule::in(array_keys(SocialAccount::PROVIDERS))],
            'label' => ['required', 'string', 'max:120'],

            /*
             * Nullable so a saved connection can have its page id corrected
             * without the token being retyped — the same contract as the Drive
             * credentials panel, and for the same reason: the browser was never
             * shown the stored value, so it has nothing to send back.
             */
            'access_token' => ['nullable', 'string', 'min:20', 'max:5000'],

            // Numeric strings, not integers: a Page id exceeds PHP's signed
            // 32-bit range on some platforms and Meta treats them as opaque.
            'page_id' => ['nullable', 'string', 'max:64', 'regex:/^[0-9]+$/'],
            'ig_user_id' => ['nullable', 'string', 'max:64', 'regex:/^[0-9]+$/'],
            'phone_number_id' => ['nullable', 'string', 'max:64', 'regex:/^[0-9]+$/'],
            'business_account_id' => ['nullable', 'string', 'max:64', 'regex:/^[0-9]+$/'],

            'token_expires_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider.in' => 'La red social seleccionada no está soportada.',
            'access_token.min' => 'El token es demasiado corto para ser un token de Meta.',
            'page_id.regex' => 'El ID de la página debe ser numérico.',
            'ig_user_id.regex' => 'El ID de la cuenta de Instagram debe ser numérico.',
            'phone_number_id.regex' => 'El ID del número de WhatsApp debe ser numérico.',
            'business_account_id.regex' => 'El ID de la cuenta de negocio debe ser numérico.',
        ];
    }
}
