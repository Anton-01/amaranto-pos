<?php

namespace App\Services\Social;

use App\Exceptions\Social\MetaGraphException;
use App\Models\SocialAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Native Meta Graph API client for photo publishing.
 *
 * WHY NOT facebook/graph-sdk. The surface this module needs is three
 * endpoints — publish a photo to a Page, create an Instagram media container,
 * publish that container — and the official SDK is abandoned for PHP 8.4 and
 * built around an app-and-session model this module does not have. Speaking
 * the REST API directly is the same decision, for the same reasons, that
 * `GoogleDriveClient` makes about Drive: the credential lives encrypted in the
 * database and is rotated from the panel, and what the SDK would do for us here
 * is two form POSTs.
 *
 * THE IMAGE IS PASSED AS A URL, NOT AS BYTES. Both flows below hand Meta a
 * link and Meta's own crawler fetches it. That is not a convenience: the
 * Instagram Content Publishing API accepts no upload at all, only `image_url`.
 * The consequence shapes the whole module — the picture must be reachable from
 * the public internet at the moment Meta looks, which is what
 * `SocialImageUrlResolver` exists to arrange for files that are private in
 * Drive.
 *
 * EVERY FAILURE CARRIES META'S OWN WORDS. Graph answers HTTP 400 with a JSON
 * body naming a numeric code, and that code IS the diagnosis: 190 is an expired
 * or revoked token, 200 a missing `pages_manage_posts` grant, 100 with subcode
 * 2207003 means the crawler could not download the image, 4 and 32 are rate
 * limits that clear on their own. The message and the code travel intact into
 * `social_posts.error_message` and `error_code`.
 */
class MetaGraphService
{
    /**
     * Publishes a photo to a Facebook Page feed.
     *
     * ONE CALL, NOT TWO. The Page `/photos` edge accepts the image URL and the
     * caption together and publishes immediately — the container dance below is
     * an Instagram-only requirement, and imitating it here would add a round
     * trip that buys nothing.
     *
     * `published=true` is explicit rather than left to the default: the same
     * edge with `published=false` produces an unpublished photo that sits
     * invisible in the Page's media library, and an operator who clicked
     * "Publicar Ahora" and sees nothing on the wall has no way to tell that
     * state from a failure.
     *
     * @return array{id: string|null, post_id: string|null, raw: array<string, mixed>}
     *
     * @throws MetaGraphException
     */
    public function publishFacebookPhoto(SocialAccount $account, string $imageUrl, string $caption): array
    {
        $pageId = (string) $account->page_id;

        $payload = $this->post($this->endpoint($pageId, 'photos'), [
            'url' => $imageUrl,
            'caption' => $caption,
            'published' => 'true',
            'access_token' => (string) $account->access_token,
        ], 'facebook.photos');

        return [
            // `post_id` is the object addressable in the feed
            // ("<page>_<post>"); `id` is the photo node. The caller stores the
            // first when Meta returns it, because that is the one that opens a
            // browser at the actual publication.
            'id' => isset($payload['id']) ? (string) $payload['id'] : null,
            'post_id' => isset($payload['post_id']) ? (string) $payload['post_id'] : null,
            'raw' => $payload,
        ];
    }

    /**
     * Publishes a photo to an Instagram Business account.
     *
     * THE TWO-STEP FLOW, AND WHY IT CANNOT BE COLLAPSED. `/media` only creates
     * a container: Meta downloads the image from the URL, transcodes it and
     * holds it. `/media_publish` is what puts it on the profile. Between the
     * two there is an asynchronous gap with no callback, and calling the second
     * too early fails with "Media ID is not available" — which reads like a bad
     * id and is really a race. So the container's `status_code` is polled until
     * it reports FINISHED, within the bounded budget in config/social.php.
     *
     * A container that never finishes is reported as a failure with the last
     * status seen. That is the honest outcome: the container exists, but it was
     * not publishable inside the window, and the usual cause is an image URL
     * their crawler could not reach.
     *
     * @return array{id: string|null, creation_id: string, raw: array<string, mixed>}
     *
     * @throws MetaGraphException
     */
    public function publishInstagramPhoto(SocialAccount $account, string $imageUrl, string $caption): array
    {
        $igUserId = (string) $account->ig_user_id;
        $token = (string) $account->access_token;

        $container = $this->post($this->endpoint($igUserId, 'media'), [
            'image_url' => $imageUrl,
            'caption' => $caption,
            'access_token' => $token,
        ], 'instagram.media');

        $creationId = isset($container['id']) ? (string) $container['id'] : '';

        if ($creationId === '') {
            throw new MetaGraphException(
                'Instagram aceptó la petición pero no devolvió un identificador de contenedor.',
                null,
                null,
                ['step' => 'instagram.media', 'response' => $container],
            );
        }

        $this->awaitInstagramContainer($creationId, $token);

        $published = $this->post($this->endpoint($igUserId, 'media_publish'), [
            'creation_id' => $creationId,
            'access_token' => $token,
        ], 'instagram.media_publish');

        return [
            'id' => isset($published['id']) ? (string) $published['id'] : null,
            'creation_id' => $creationId,
            'raw' => $published,
        ];
    }

    /**
     * Reads back the identity a token actually grants.
     *
     * Used by the connection test so an administrator learns that a token is
     * dead, or belongs to the wrong page, at the moment they paste it — not
     * hours later from a failed publication. `id,name` and nothing else: the
     * test must not need a permission the publishing flow does not already
     * have.
     *
     * @return array<string, mixed>
     *
     * @throws MetaGraphException
     */
    public function describeNode(SocialAccount $account): array
    {
        $nodeId = $account->graphNodeId();

        if (blank($nodeId)) {
            throw new MetaGraphException(
                'La conexión no tiene un identificador de destino configurado.',
                null,
                null,
                ['provider' => $account->provider],
            );
        }

        $fields = $account->provider === SocialAccount::PROVIDER_INSTAGRAM
            ? 'id,username,name'
            : 'id,name';

        return $this->get($this->endpoint((string) $nodeId), [
            'fields' => $fields,
            'access_token' => (string) $account->access_token,
        ], 'describe_node');
    }

    /**
     * Blocks until the container is publishable, or gives up.
     *
     * The sleep is deliberate and it is why this class only ever runs inside a
     * queued job: parking an HTTP request for up to half a minute waiting on
     * Meta's transcoder is exactly the blocking the POS interface must never
     * do.
     *
     * ERROR and EXPIRED are terminal and are raised immediately instead of
     * being polled out — waiting on a container Meta has already given up on
     * only delays the report.
     *
     * @throws MetaGraphException
     */
    private function awaitInstagramContainer(string $creationId, string $token): void
    {
        $attempts = max(1, (int) config('social.instagram.container_poll_attempts', 10));
        $interval = max(1, (int) config('social.instagram.container_poll_seconds', 3));
        $lastStatus = 'UNKNOWN';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $status = $this->get($this->endpoint($creationId), [
                'fields' => 'status_code,status',
                'access_token' => $token,
            ], 'instagram.container_status');

            $lastStatus = (string) ($status['status_code'] ?? 'UNKNOWN');

            if ($lastStatus === 'FINISHED' || $lastStatus === 'PUBLISHED') {
                return;
            }

            if ($lastStatus === 'ERROR' || $lastStatus === 'EXPIRED') {
                throw new MetaGraphException(
                    'Instagram no pudo procesar la imagen: '.($status['status'] ?? $lastStatus),
                    null,
                    null,
                    ['step' => 'instagram.container_status', 'creation_id' => $creationId, 'response' => $status],
                );
            }

            // Nothing to wait for after the last look — sleeping here would
            // only add the interval to the failure's latency.
            if ($attempt < $attempts) {
                sleep($interval);
            }
        }

        throw new MetaGraphException(
            "El contenedor de Instagram no quedó listo tras {$attempts} revisiones (último estado: {$lastStatus}). "
            .'La causa habitual es que Meta no alcanzó la URL de la imagen.',
            null,
            null,
            ['step' => 'instagram.container_status', 'creation_id' => $creationId, 'last_status' => $lastStatus],
        );
    }

    /**
     * POSTs a form body to Graph and returns its decoded answer.
     *
     * `asForm()` and not `asJson()`: Graph's publishing edges are documented
     * and behave as form endpoints, and a JSON body is silently ignored on some
     * of them — the call succeeds with a 200 and an empty object, which is far
     * harder to diagnose than a rejection.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws MetaGraphException
     */
    private function post(string $url, array $payload, string $step): array
    {
        try {
            $response = $this->http()->asForm()->post($url, $payload);
        } catch (Throwable $e) {
            throw $this->transportFailure($e, $step);
        }

        return $this->decode($response, $step);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws MetaGraphException
     */
    private function get(string $url, array $query, string $step): array
    {
        try {
            $response = $this->http()->get($url, $query);
        } catch (Throwable $e) {
            throw $this->transportFailure($e, $step);
        }

        return $this->decode($response, $step);
    }

    /**
     * Turns a Graph response into an array, or into an exception carrying
     * Meta's own diagnosis.
     *
     * @return array<string, mixed>
     *
     * @throws MetaGraphException
     */
    private function decode(Response $response, string $step): array
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->successful() && ! isset($body['error'])) {
            return $body;
        }

        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        $message = (string) ($error['message'] ?? '');
        $code = isset($error['code']) ? (string) $error['code'] : null;
        $subcode = isset($error['error_subcode']) ? (string) $error['error_subcode'] : null;

        if ($message === '') {
            // Not Meta answering: a proxy, a gateway or an HTML error page.
            // Saying so is more useful than quoting a truncated <html>.
            $message = 'La API de Meta respondió '.$response->status().' sin un cuerpo de error interpretable.';
        }

        /*
         * The trace id is worth keeping. It is the one token Meta's own support
         * asks for, and it is the only way to correlate a failure here with
         * what their side recorded.
         */
        $context = array_filter([
            'step' => $step,
            'graph_code' => $code,
            'graph_subcode' => $subcode,
            'graph_type' => $error['type'] ?? null,
            'fbtrace_id' => $error['fbtrace_id'] ?? null,
        ], fn ($value) => $value !== null);

        Log::warning('[Social] La API de Meta rechazó la petición.', $context + [
            'status' => $response->status(),
            'message' => $message,
        ]);

        throw new MetaGraphException(
            $message,
            $response->status(),
            $subcode !== null ? $code.'/'.$subcode : $code,
            $context,
        );
    }

    /**
     * A connection that never completed: blocked egress, DNS failure, TLS
     * rejected. Meta said nothing, so there is no provider text to preserve and
     * the transport's own message is the diagnosis.
     */
    private function transportFailure(Throwable $e, string $step): MetaGraphException
    {
        return new MetaGraphException(
            'No se pudo contactar a la API de Meta: '.$e->getMessage(),
            null,
            null,
            ['step' => $step],
            $e,
        );
    }

    /** Fully qualified Graph URL for a node and, optionally, one of its edges. */
    private function endpoint(string $nodeId, ?string $edge = null): string
    {
        $base = rtrim((string) config('social.meta.base_url'), '/');
        $version = trim((string) config('social.meta.version'), '/');

        return $base.'/'.$version.'/'.$nodeId.($edge !== null ? '/'.$edge : '');
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('social.meta.timeout', 30))
            ->connectTimeout((int) config('social.meta.connect_timeout', 10))
            ->acceptJson();
    }
}
