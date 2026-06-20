<?php

namespace App\Http\Requests\CashRegisterClosing;

use Illuminate\Foundation\Http\FormRequest;

class SendCashRegisterClosingEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'emails'     => 'required|array|min:1|max:20',
            'emails.*'   => 'required|email',
            'date_from'  => 'nullable|date_format:Y-m-d',
            'date_to'    => 'nullable|date_format:Y-m-d',
        ];
    }

    public function messages(): array
    {
        return [
            'emails.required'  => 'Debes especificar al menos un correo destinatario.',
            'emails.*.email'   => 'Uno o más correos no tienen un formato válido.',
            'emails.max'       => 'Puedes enviar a un máximo de 20 destinatarios.',
        ];
    }
}
