<?php

namespace App\Http\Requests\TableSession;

use App\Services\CancellationAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;

class CancelTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The reason is the whole point of the audit record: a cancellation
            // without a stated motive is indistinguishable from theft.
            'reason' => ['required', 'string', 'min:5', 'max:500'],

            // Administrators cancel on the strength of their role, so the field
            // is only required for everybody else. The password itself is
            // verified in the controller against the stored hash.
            'authorization_password' => [
                $this->userIsPrivileged() ? 'nullable' : 'required',
                'string',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Debes escribir el motivo de la cancelacion.',
            'reason.min' => 'El motivo debe ser explicito: escribe al menos 5 caracteres.',
            'authorization_password.required' => 'Se requiere la contrasena de autorizacion para cancelar la mesa.',
        ];
    }

    private function userIsPrivileged(): bool
    {
        return (bool) $this->user()?->hasRole(CancellationAuthorizationService::PRIVILEGED_ROLE);
    }
}
