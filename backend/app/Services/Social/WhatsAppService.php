<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Services\Social\Contracts\SocialChannelPublisher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WhatsApp publisher — WIRED END TO END, SIMULATED ON PURPOSE.
 *
 * Everything around this class is real: the channel appears in the composer,
 * the job branches to it, and every attempt lands in `social_posts` with the
 * same statuses as Facebook and Instagram. What it does NOT do is touch the
 * network, and the reason is a product decision rather than missing work.
 *
 * WHY THERE IS NO REAL CALL YET. The WhatsApp Cloud API publishes messages to
 * conversations, not statuses to an audience: there is no `/status` edge, and
 * the 24-hour customer service window means an unsolicited image can only go
 * out as a pre-approved template to numbers that opted in. Picking between a
 * template blast, a broadcast list and an on-device bridge is a decision with
 * legal and cost consequences, and guessing at it here would produce an
 * integration that has to be torn out.
 *
 * WHAT THE MOCK IS FOR. The pipeline can be exercised end to end today — the
 * toggle, the job branch, the log row, the badge in the history — so the only
 * thing the real integration will have to change is the body of `publish()`.
 * The simulated id is prefixed `wa_mock_` so no reader of the log can ever
 * mistake it for a message id that exists at Meta, and the row's context says
 * `simulated: true` for the same reason.
 */
class WhatsAppService implements SocialChannelPublisher
{
    public function provider(): string
    {
        return SocialAccount::PROVIDER_WHATSAPP;
    }

    /**
     * Records a simulated publication.
     *
     * The signature is already the real one, so the replacement is a body swap:
     * a Cloud API call against `phone_number_id` with the same image URL and
     * caption this method receives.
     *
     * @return array{id: string|null, context: array<string, mixed>}
     */
    public function publish(SocialAccount $account, string $imageUrl, string $caption): array
    {
        $reference = 'wa_mock_'.Str::lower(Str::random(16));

        Log::info('[Social] Publicación de WhatsApp simulada (integración pendiente).', [
            'social_account_id' => $account->id,
            'phone_number_id' => $account->phone_number_id,
            'reference' => $reference,
            'caption_length' => mb_strlen($caption),
        ]);

        return [
            'id' => $reference,
            'context' => [
                'simulated' => true,
                'image_url' => $imageUrl,
                'notice' => 'Integración real de WhatsApp pendiente: esta publicación no salió a la red.',
            ],
        ];
    }

    /** True while the channel is a simulation rather than a real sender. */
    public function isMocked(): bool
    {
        return (bool) config('social.whatsapp.mock', true);
    }
}
