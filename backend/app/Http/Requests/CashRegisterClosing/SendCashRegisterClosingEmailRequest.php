<?php

namespace App\Http\Requests\CashRegisterClosing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload of the manual cash closings report.
 *
 * `emails` is optional on purpose: omitting it means "send to whoever the
 * `sales` mail configuration says", which is the default the modal offers.
 * Sending it means "this time, deliver to these addresses instead" — the
 * ad-hoc override, valid for one message and never written back to the stored
 * configuration.
 */
class SendCashRegisterClosingEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'emails'     => 'nullable|array|max:20',
            'emails.*'   => 'required|email',
            'date_from'  => 'nullable|date_format:Y-m-d',
            'date_to'    => 'nullable|date_format:Y-m-d',
        ];
    }

    public function messages(): array
    {
        return [
            'emails.*.email'   => 'Uno o más correos no tienen un formato válido.',
            'emails.max'       => 'Puedes enviar a un máximo de 20 destinatarios.',
        ];
    }

    /**
     * Ad-hoc recipients, or null when the configured list should be used.
     *
     * An empty array collapses to null so that a modal submitted with every
     * chip removed falls back to the official distribution list instead of
     * queuing a message addressed to nobody.
     *
     * @return array<int, string>|null
     */
    public function adHocRecipients(): ?array
    {
        $emails = collect($this->input('emails', []))
            ->map(fn ($email) => trim((string) $email))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $emails === [] ? null : $emails;
    }
}
