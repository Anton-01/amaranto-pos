<?php

namespace App\Jobs;

use App\Exceptions\Social\MetaGraphException;
use App\Models\MediaAuditLog;
use App\Models\MediaFile;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Services\Media\MediaAuditLogger;
use App\Services\Social\MetaGraphService;
use App\Services\Social\SocialImageUrlResolver;
use App\Services\Social\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Publishes one library image to the selected networks, off the request.
 *
 * WHY THIS IS A JOB AT ALL. A Facebook photo post is one call, but an Instagram
 * one is a container creation followed by a poll until Meta finishes
 * transcoding — seconds at best, half a minute when their side is slow. Running
 * that inside the HTTP request would park a PHP-FPM worker on Meta's latency
 * and hand the cashier a spinner, and with three channels selected it would hit
 * Nginx's timeout outright. The composer therefore gets a 202 and this runs on
 * the `queue-worker` container.
 *
 * ONE ATTEMPT, DELIBERATELY. `$tries = 1` looks wrong for a network job and is
 * the opposite: the unit of work here is three independent publications, and a
 * framework-level retry would re-run the WHOLE job. A run where Facebook
 * succeeded and Instagram failed would, on its second attempt, publish the
 * Facebook photo a second time — a duplicate post on a customer-facing page
 * that nobody asked for and that this module cannot detect afterwards. Retrying
 * is the operator's decision, taken per channel from the log.
 *
 * FAILURE IS ISOLATED PER CHANNEL. Each provider runs inside its own try/catch
 * and closes its own `social_posts` row. Instagram rejecting an image never
 * costs the Facebook publication, which is exactly the shape the log's
 * one-row-per-channel design was built for.
 */
class PublishSocialMediaPost implements ShouldQueue
{
    use Queueable;

    /** See the class docblock: a retry here means duplicate public posts. */
    public int $tries = 1;

    /**
     * Generous, and bounded by the Instagram poll rather than by hope. The
     * budget in config/social.php is attempts × interval per Instagram channel,
     * plus the HTTP timeouts; 300 seconds covers the three channels together
     * with room to spare, and anything past it is a hung call worth killing.
     */
    public int $timeout = 300;

    /**
     * @param  array<int, string>  $providers
     */
    public function __construct(
        public readonly string $mediaFileId,
        public readonly string $caption,
        public readonly array $providers,
        public readonly string $batchId,
        public readonly ?string $actorId = null,
    ) {}

    public function handle(
        MetaGraphService $meta,
        WhatsAppService $whatsApp,
        SocialImageUrlResolver $imageUrls,
        MediaAuditLogger $audit,
    ): void {
        $rows = SocialPost::where('batch_id', $this->batchId)->get()->keyBy('provider');

        if ($rows->isEmpty()) {
            // Nothing to report against. Logging and returning is right: the
            // alternative, throwing, would only requeue a job whose evidence
            // does not exist.
            Log::warning('[Social] El lote de publicación no tiene bitácora.', ['batch_id' => $this->batchId]);

            return;
        }

        $file = MediaFile::find($this->mediaFileId);
        $actor = $this->resolveActor();

        if (! $file) {
            $this->failEveryPendingRow($rows->all(), 'La imagen ya no existe en la biblioteca.');

            return;
        }

        /*
         * The public URL is minted ONCE for the whole batch. Three channels do
         * not need three share links — they need the same picture — and a
         * single link means a single row to revoke if the exposure ever has to
         * be closed by hand.
         */
        try {
            ['url' => $imageUrl] = $imageUrls->forFile($file, $actor);
        } catch (Throwable $e) {
            $this->failEveryPendingRow($rows->all(), $e->getMessage());

            return;
        }

        foreach ($this->providers as $provider) {
            $post = $rows->get($provider);

            if (! $post || $post->status !== SocialPost::STATUS_PENDING) {
                continue;
            }

            $this->publishOne($post, $provider, $imageUrl, $meta, $whatsApp);
        }

        $this->recordAudit($audit, $file, $rows->all(), $actor);
    }

    /**
     * Runs one channel and closes its row, whatever happens.
     *
     * The catch is `Throwable` and not `MetaGraphException`: a bug in this
     * module must not leave a `publishing` row open forever, because a row that
     * never closes is indistinguishable from a network the operator is still
     * waiting on.
     */
    private function publishOne(
        SocialPost $post,
        string $provider,
        string $imageUrl,
        MetaGraphService $meta,
        WhatsAppService $whatsApp,
    ): void {
        try {
            $account = SocialAccount::requireActiveFor($provider);

            $post->markPublishing($imageUrl, $account);

            $result = match ($provider) {
                SocialAccount::PROVIDER_FACEBOOK => $this->normalize(
                    $meta->publishFacebookPhoto($account, $imageUrl, $this->caption),
                    fn (array $raw) => $raw['post_id'] ?? $raw['id'],
                ),
                SocialAccount::PROVIDER_INSTAGRAM => $this->normalize(
                    $meta->publishInstagramPhoto($account, $imageUrl, $this->caption),
                    fn (array $raw) => $raw['id'],
                ),
                SocialAccount::PROVIDER_WHATSAPP => $whatsApp->publish($account, $imageUrl, $this->caption),
                default => throw new RuntimeException("Canal no soportado: {$provider}."),
            };

            $post->markSuccess($result['id'], $result['context']);
        } catch (Throwable $e) {
            $post->markFailed($e->getMessage(), $this->errorCodeOf($e));

            Log::error('[Social] Falló la publicación en un canal.', [
                'batch_id' => $this->batchId,
                'provider' => $provider,
                'social_post_id' => $post->id,
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Reduces a provider result to the id worth storing plus the rest as
     * context.
     *
     * @param  array<string, mixed>  $result
     * @param  callable(array<string, mixed>): mixed  $pickId
     * @return array{id: string|null, context: array<string, mixed>}
     */
    private function normalize(array $result, callable $pickId): array
    {
        $id = $pickId($result);

        return [
            'id' => $id === null ? null : (string) $id,
            'context' => array_diff_key($result, ['raw' => null]),
        ];
    }

    /** Meta's numeric code when the failure came from Graph, null otherwise. */
    private function errorCodeOf(Throwable $e): ?string
    {
        return $e instanceof MetaGraphException ? $e->graphCode : null;
    }

    /**
     * Closes every row that never reached a network with the same reason.
     *
     * Used for the failures that happen BEFORE any channel runs — a deleted
     * image, a share link that could not be minted. Rows already resolved are
     * left alone so a partial run is never rewritten.
     *
     * @param  array<string, SocialPost>  $rows
     */
    private function failEveryPendingRow(array $rows, string $reason): void
    {
        foreach ($rows as $post) {
            if (in_array($post->status, [SocialPost::STATUS_PENDING, SocialPost::STATUS_PUBLISHING], true)) {
                $post->markFailed($reason);
            }
        }

        Log::error('[Social] El lote de publicación no pudo iniciar.', [
            'batch_id' => $this->batchId,
            'reason' => $reason,
        ]);
    }

    /**
     * Leaves the batch's outcome in the media module's forensic trail.
     *
     * The publication is already logged in `social_posts`, but the media audit
     * is where an investigator looks to ask "what has ever been done with this
     * file" — and pushing an image to a public network belongs in that answer
     * next to its downloads and its share links.
     *
     * @param  array<string, SocialPost>  $rows
     */
    private function recordAudit(MediaAuditLogger $audit, MediaFile $file, array $rows, User $actor): void
    {
        $outcome = collect($rows)->mapWithKeys(fn (SocialPost $post) => [
            $post->provider => [
                'status' => $post->status,
                'api_response_id' => $post->api_response_id,
                'error' => $post->error_message,
            ],
        ])->all();

        $audit->record(MediaAuditLog::ACTION_SOCIAL_PUBLISH, $file, [
            'batch_id' => $this->batchId,
            'caption_length' => mb_strlen($this->caption),
            'channels' => $outcome,
        ], $actor);
    }

    /**
     * The user the publication is attributed to.
     *
     * Falls back to the automated identity rather than to null: minting a share
     * link needs an actor, and an unattributed one in the media audit trail is
     * exactly the gap the trail exists to prevent.
     */
    private function resolveActor(): User
    {
        return ($this->actorId ? User::find($this->actorId) : null) ?? User::systemProcess();
    }

    /**
     * A job that dies outright — timeout, worker killed, an error before the
     * per-channel catch could run — must not leave the log frozen in `pending`.
     */
    public function failed(Throwable $exception): void
    {
        $rows = SocialPost::where('batch_id', $this->batchId)->get()->all();

        $this->failEveryPendingRow(
            collect($rows)->keyBy('provider')->all(),
            'El proceso de publicación terminó inesperadamente: '.$exception->getMessage(),
        );
    }
}
