<?php

namespace App\Http\Requests\Social;

use App\Models\SocialAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PublishSocialPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            /*
             * Nullable, because a photo post with no words is a legitimate
             * publication on all three networks. The per-network ceilings are
             * checked in withValidator(), where the selected channels are
             * known — a 3 000-character caption is fine for Facebook and
             * rejected by Instagram, so the limit cannot be a constant here.
             */
            'caption' => ['nullable', 'string', 'max:5000'],

            // At least one channel. A submission with none would queue a job
            // with nothing to do and write no evidence of the attempt.
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', Rule::in(array_keys(SocialAccount::PROVIDERS))],
        ];
    }

    /**
     * Enforces each selected network's own caption ceiling.
     *
     * Checked here rather than left to the provider: Instagram silently
     * truncates a long caption instead of rejecting it, so the operator would
     * only discover the loss by reading the published post.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $caption = (string) $this->input('caption', '');
            $length = mb_strlen($caption);
            $limits = (array) config('social.captions', []);

            foreach ((array) $this->input('channels', []) as $channel) {
                $limit = (int) ($limits[$channel] ?? 0);

                if ($limit > 0 && $length > $limit) {
                    $label = SocialAccount::PROVIDERS[$channel] ?? $channel;
                    $validator->errors()->add(
                        'caption',
                        "El texto excede el límite de {$label} ({$limit} caracteres)."
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'channels.required' => 'Selecciona al menos una red social.',
            'channels.*.in' => 'Una de las redes seleccionadas no está soportada.',
        ];
    }
}
