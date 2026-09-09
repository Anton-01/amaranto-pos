<?php

namespace App\Services\Social;

use App\Jobs\PublishSocialMediaPost;
use App\Models\MediaFile;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accepts a publication request, writes its evidence and hands it to the queue.
 *
 * THE ORDER OF THE TWO OPERATIONS IS THE POINT. The `social_posts` rows are
 * created inside a transaction that commits BEFORE the job is dispatched, so
 * the queue can never pick up work whose log rows do not exist yet — a race
 * that with a fast Redis worker is not theoretical. The job therefore always
 * finds its rows, and a worker that dies leaves `pending` rows an operator can
 * see rather than silence.
 *
 * WHAT THE CALLER GETS BACK IMMEDIATELY. The rows, in `pending`. The composer
 * closes on that answer and the POS interface is never blocked on Meta, which
 * is the whole reason this module is asynchronous: an Instagram container can
 * take half a minute to transcode, and Nginx would have timed out the request
 * long before.
 */
class SocialPublishingService
{
    /**
     * Queues one publication per selected channel and returns the log rows.
     *
     * @param  array<int, string>  $providers
     * @return Collection<int, SocialPost>
     */
    public function queue(MediaFile $file, User $actor, string $caption, array $providers): Collection
    {
        $batchId = (string) Str::uuid();

        $rows = DB::transaction(function () use ($file, $actor, $caption, $providers, $batchId) {
            return collect($providers)
                ->unique()
                ->values()
                ->map(fn (string $provider) => SocialPost::create([
                    'batch_id' => $batchId,
                    'media_file_id' => $file->id,
                    // Resolved again inside the job rather than pinned here: a
                    // credential rotated between the click and the worker must
                    // publish with the NEW token, not with the one that was
                    // live when the operator pressed the button.
                    'social_account_id' => SocialAccount::activeFor($provider)?->id,
                    'provider' => $provider,
                    'status' => SocialPost::STATUS_PENDING,
                    'caption' => $caption,
                    'created_by' => $actor->id,
                ]));
        });

        PublishSocialMediaPost::dispatch(
            mediaFileId: $file->id,
            caption: $caption,
            providers: $rows->pluck('provider')->all(),
            batchId: $batchId,
            actorId: $actor->id,
        );

        return $rows;
    }

    /**
     * Connection status of every channel, for the composer.
     *
     * The composer must be able to disable a toggle BEFORE the operator writes
     * a caption for a network that has no credentials — discovering it after
     * the click costs them the text they just typed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function channelStatus(): array
    {
        $captions = (array) config('social.captions', []);

        return collect(SocialAccount::PROVIDERS)
            ->reject(fn (string $label, string $provider) => $provider === SocialAccount::PROVIDER_WHATSAPP
                && ! config('social.whatsapp.enabled', true))
            ->map(function (string $label, string $provider) use ($captions) {
                $account = SocialAccount::activeFor($provider);
                $simulated = $provider === SocialAccount::PROVIDER_WHATSAPP
                    && (bool) config('social.whatsapp.mock', true);

                return [
                    'provider' => $provider,
                    'label' => $label,
                    'connected' => (bool) $account?->isUsable(),
                    'account_label' => $account?->label,
                    'expired' => (bool) $account?->isExpired(),
                    'missing' => $account?->missingPieces() ?? [],
                    // A simulated channel is publishable and says so. Hiding
                    // the simulation would let an operator believe a status
                    // went out that never left the server.
                    'simulated' => $simulated,
                    'caption_limit' => (int) ($captions[$provider] ?? 2200),
                ];
            })
            ->values()
            ->all();
    }
}
