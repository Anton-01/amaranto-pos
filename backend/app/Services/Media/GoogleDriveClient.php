<?php

namespace App\Services\Media;

use App\Exceptions\Media\GoogleDriveException;
use App\Models\DriveCredential;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Native Google Drive v3 client driven by an OAuth 2.0 grant held in the
 * database.
 *
 * WHY NOT google/apiclient. The surface this module needs is small — refresh a
 * token, create a folder, upload a file, rewrite its permissions, read it back
 * — and the official SDK is built around credentials that live in a file on
 * disk or in an environment variable. This module's whole premise is the
 * opposite: the credential lives encrypted in the database and is rotated from
 * the admin panel. Speaking the REST API directly keeps that path under our
 * control and avoids bending an SDK into a shape it does not want. What the SDK
 * would do for us here is one form POST, which is exactly what
 * exchangeRefreshToken() below is.
 *
 * AUTHENTICATION IS USER CONTEXT, NOT A SERVICE ACCOUNT. The client sends the
 * OAuth client id, the client secret and the stored refresh token to Google's
 * token endpoint with `grant_type=refresh_token`, and Google answers with a
 * short-lived access token minted FOR THE PERSON who granted it. Every call
 * below is therefore made as that person.
 *
 * WHY THE SERVICE ACCOUNT WAS ABANDONED. A service account is an identity with
 * no storage quota of its own. Drive bills a new object to whoever owns it, so
 * every upload into an ordinary My Drive folder was charged to an account that
 * owns zero bytes and came back `403 [storageQuotaExceeded]`, whatever the
 * sharing grant said. Google's remedy is a Shared Drive, where the drive owns
 * its contents — and Shared Drives are a Workspace feature that does not exist
 * on the personal Google One account this deployment runs against. A refresh
 * token from the account's owner removes the problem instead of working around
 * it: the files belong to them and consume the plan they already pay for.
 *
 * NO SHARED-DRIVE PARAMETERS. `supportsAllDrives`, `includeItemsFromAllDrives`
 * and `corpora=allDrives` are gone from every call. They exist to make objects
 * inside shared drives addressable; against a personal Drive they address
 * nothing, and carrying them would only suggest a storage model this module no
 * longer has.
 *
 * SCOPE. Requested from `config('media.drive.scope')`, and it stays `drive`
 * rather than `drive.file`: the root folder is one the owner created by hand,
 * and `drive.file` reaches only objects this application itself created. Drive
 * reports anything outside the token's universe as `404 File not found`, which
 * reads as a mistyped folder id. See config/media.php for the trade-off.
 */
class GoogleDriveClient
{
    /**
     * Access token for a credential row, refreshed on demand and cached until
     * shortly before it expires.
     *
     * The leeway matters: a token cached for its full hour can be handed to a
     * request that departs 200 ms before the expiry and arrives after it,
     * producing a 401 that no retry policy explains. Retiring it five minutes
     * early makes that race impossible.
     *
     * @throws GoogleDriveException when Google refuses the refresh token.
     */
    public function accessToken(DriveCredential $credential): string
    {
        $cacheKey = DriveCredential::TOKEN_CACHE_PREFIX.$credential->id;

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $leeway = (int) config('media.drive.token_leeway', 300);

        ['token' => $token, 'expires_in' => $expiresIn] = $this->exchangeRefreshToken($credential);

        Cache::put($cacheKey, $token, max(60, $expiresIn - $leeway));

        return $token;
    }

    /**
     * Trades the stored refresh token for an access token.
     *
     * This is the whole authenticator. There is no assertion to build and no
     * key to sign with: the refresh token IS the long-lived credential, and the
     * client id/secret pair only proves which OAuth application is redeeming
     * it. That is also why all three are stored together and rotated together —
     * a refresh token is bound to the client that obtained it and is worthless
     * against another one.
     *
     * The lifetime comes from Google's own `expires_in` rather than from a
     * constant. Google currently answers one hour, but a token cached for
     * longer than it lives produces 401s that look like a permissions problem,
     * so the provider's answer is the one that decides.
     *
     * @return array{token: string, expires_in: int}
     *
     * @throws GoogleDriveException
     */
    private function exchangeRefreshToken(DriveCredential $credential): array
    {
        try {
            $response = $this->http(config('media.drive.timeout'))
                ->asForm()
                ->post(config('media.drive.token_endpoint'), [
                    'grant_type' => 'refresh_token',
                    'client_id' => (string) $credential->client_id,
                    'client_secret' => (string) $credential->client_secret,
                    'refresh_token' => (string) $credential->refresh_token,
                ]);
        } catch (Throwable $e) {
            // A connection that never completed: blocked egress, DNS failure,
            // TLS rejected. Google said nothing, so there is no provider text
            // to preserve — the transport's own message is the diagnosis.
            throw new GoogleDriveException(
                'No se pudo contactar a Google para renovar el token de acceso: '.$e->getMessage(),
                null,
                ['stage' => 'token'],
                $e,
            );
        }

        if ($response->failed()) {
            throw new GoogleDriveException(
                $this->describeTokenFailure($response),
                $response->status(),
                ['stage' => 'token', 'reason' => $this->failureReason($response)],
            );
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new GoogleDriveException(
                'Google respondió sin un access_token utilizable.',
                $response->status(),
                ['stage' => 'token'],
            );
        }

        $expiresIn = (int) ($response->json('expires_in') ?? config('media.drive.token_ttl', 3600));

        return ['token' => $token, 'expires_in' => max(60, $expiresIn)];
    }

    /**
     * The token endpoint's failures, translated into the three things that are
     * actually wrong when a refresh stops working.
     *
     * Google answers all of them with the same opaque `invalid_grant` or
     * `invalid_client`, and the raw wording ("Bad Request") sends an
     * administrator to check the folder id, which is never the cause at this
     * stage. Naming the three real causes is what turns a dead end into a fix.
     */
    private function describeTokenFailure(Response $response): string
    {
        $error = (string) ($response->json('error') ?? '');
        $base = $this->describeFailure($response, 'Google rechazó la renovación del token de OAuth');

        return match ($error) {
            'invalid_grant' => $base.' El Refresh Token ya no es válido. Las causas habituales son: '
                .'1) el usuario revocó el acceso de la aplicación desde su cuenta de Google; '
                .'2) el token se generó con un Client ID distinto al configurado aquí; '
                .'3) la aplicación de OAuth sigue en modo "Testing" en Google Cloud, donde los '
                .'refresh tokens caducan a los 7 días — publícala para que dejen de expirar. '
                .'Genera un Refresh Token nuevo y vuelve a guardarlo.',
            'invalid_client' => $base.' El Client ID o el Client Secret no corresponden a una '
                .'aplicación OAuth válida. Cópialos de nuevo desde Google Cloud Console → '
                .'Credenciales → ID de cliente de OAuth 2.0.',
            default => $base,
        };
    }

    /**
     * Ensures a named subfolder exists under a parent and returns its id.
     *
     * The lookup runs before the creation so restarting the container, or two
     * uploads racing in the same minute, cannot leave the library with two
     * folders of the same name. `trashed = false` is part of the query because
     * a folder somebody moved to Drive's trash still answers a plain name
     * search, and uploading into it would silently hide every new file.
     *
     * @throws GoogleDriveException
     */
    public function ensureFolder(DriveCredential $credential, string $name, string $parentId): string
    {
        $escaped = str_replace("'", "\\'", $name);

        $existing = $this->request($credential, 'get', '/files', [
            'q' => "name = '{$escaped}' and mimeType = 'application/vnd.google-apps.folder' "
                ."and '{$parentId}' in parents and trashed = false",
            'fields' => 'files(id,name)',
            'pageSize' => 1,
        ]);

        $found = $existing->json('files.0.id');

        if (is_string($found) && $found !== '') {
            return $found;
        }

        $created = $this->request($credential, 'post', '/files', [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parentId],
        ], ['fields' => 'id']);

        $id = $created->json('id');

        if (! is_string($id) || $id === '') {
            throw new GoogleDriveException(
                'Google no devolvió el identificador de la carpeta creada.',
                $created->status(),
                ['stage' => 'ensure_folder', 'folder' => $name],
            );
        }

        return $id;
    }

    /**
     * Uploads bytes with a multipart request and returns Drive's metadata.
     *
     * Multipart (metadata + content in one request) rather than resumable: the
     * module's ceiling is a few tens of megabytes, which one request handles
     * comfortably, and a resumable session would add a round trip and a state
     * machine to every upload in exchange for resilience this size does not
     * need.
     *
     * The body is assembled by hand because the metadata part must carry
     * `Content-Type: application/json` while the content part carries the
     * file's own type — a shape the framework's `attach()` helper does not
     * express.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     *
     * @throws GoogleDriveException
     */
    public function uploadFile(
        DriveCredential $credential,
        string $contents,
        array $metadata,
        string $mimeType,
    ): array {
        $boundary = 'cronos-media-'.bin2hex(random_bytes(12));

        $body = "--{$boundary}\r\n"
            ."Content-Type: application/json; charset=UTF-8\r\n\r\n"
            .json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\r\n"
            ."--{$boundary}\r\n"
            ."Content-Type: {$mimeType}\r\n\r\n"
            .$contents."\r\n"
            ."--{$boundary}--";

        $url = rtrim(config('media.drive.upload_base'), '/').'/files';

        try {
            $response = $this->http(config('media.drive.upload_timeout'))
                ->withToken($this->accessToken($credential))
                ->withBody($body, "multipart/related; boundary={$boundary}")
                ->post($url.'?'.http_build_query([
                    'uploadType' => 'multipart',
                    'fields' => 'id,name,mimeType,size,parents,webViewLink,md5Checksum,createdTime',
                ]));
        } catch (Throwable $e) {
            throw new GoogleDriveException(
                'La subida a Google Drive no pudo completarse: '.$e->getMessage(),
                null,
                ['stage' => 'upload'],
                $e,
            );
        }

        if ($response->failed()) {
            throw new GoogleDriveException(
                $this->describeFailure($response, 'Google Drive rechazó la subida del archivo'),
                $response->status(),
                // The reason code is what separates the two 403s this call can
                // produce: `storageQuotaExceeded` is the owner's Drive being
                // genuinely full, while `insufficientFilePermissions` is the
                // grant being too narrow. They need opposite fixes.
                ['stage' => 'upload', 'reason' => $this->failureReason($response)],
            );
        }

        return (array) $response->json();
    }

    /**
     * Rewrites a file's metadata in Drive (currently only its name).
     *
     * @return array<string, mixed>
     *
     * @throws GoogleDriveException
     */
    public function updateFileMetadata(DriveCredential $credential, string $fileId, array $metadata): array
    {
        return (array) $this->request(
            $credential,
            'patch',
            "/files/{$fileId}",
            $metadata,
            ['fields' => 'id,name,mimeType,size'],
        )->json();
    }

    /**
     * Every permission currently set on a file.
     *
     * This is the read half of the privacy guarantee: the module does not
     * assume what Drive granted, it asks and then corrects.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws GoogleDriveException
     */
    public function listPermissions(DriveCredential $credential, string $fileId): array
    {
        $response = $this->request($credential, 'get', "/files/{$fileId}/permissions", [
            'fields' => 'permissions(id,type,role,emailAddress,domain,allowFileDiscovery)',
        ]);

        return (array) ($response->json('permissions') ?? []);
    }

    /** @throws GoogleDriveException */
    public function deletePermission(DriveCredential $credential, string $fileId, string $permissionId): void
    {
        $this->request($credential, 'delete', "/files/{$fileId}/permissions/{$permissionId}");
    }

    /**
     * Grants one named account read access.
     *
     * `sendNotificationEmail=false` because a POS uploading a hundred invoices
     * must not fire a hundred emails at the accountant, and because the grant
     * is an infrastructural fact, not an invitation to collaborate.
     *
     * @throws GoogleDriveException
     */
    public function grantReader(DriveCredential $credential, string $fileId, string $email): void
    {
        $this->request($credential, 'post', "/files/{$fileId}/permissions", [
            'type' => 'user',
            'role' => 'reader',
            'emailAddress' => $email,
        ], ['sendNotificationEmail' => 'false']);
    }

    /**
     * Downloads a file's bytes.
     *
     * Returned as a string rather than a stream because every consumer in this
     * module (preview frame, download response, share endpoint) needs the whole
     * object anyway, and the size ceiling keeps that bounded.
     *
     * @throws GoogleDriveException
     */
    public function downloadFile(DriveCredential $credential, string $fileId): string
    {
        try {
            $response = $this->http(config('media.drive.upload_timeout'))
                ->withToken($this->accessToken($credential))
                ->get(
                    rtrim(config('media.drive.api_base'), '/')."/files/{$fileId}",
                    ['alt' => 'media'],
                );
        } catch (Throwable $e) {
            throw new GoogleDriveException(
                'No se pudo descargar el archivo desde Google Drive: '.$e->getMessage(),
                null,
                ['stage' => 'download'],
                $e,
            );
        }

        if ($response->failed()) {
            throw new GoogleDriveException(
                $this->describeFailure($response, 'Google Drive rechazó la descarga'),
                $response->status(),
                ['stage' => 'download', 'reason' => $this->failureReason($response)],
            );
        }

        return $response->body();
    }

    /**
     * Moves a file to Drive's trash.
     *
     * Trash and not a permanent delete: the POS has its own trash module and a
     * deletion there must stay reversible. A hard delete in Drive would make
     * "restore" a lie the moment somebody clicked it.
     *
     * @throws GoogleDriveException
     */
    public function trashFile(DriveCredential $credential, string $fileId): void
    {
        $this->request($credential, 'patch', "/files/{$fileId}", ['trashed' => true]);
    }

    /** @throws GoogleDriveException */
    public function untrashFile(DriveCredential $credential, string $fileId): void
    {
        $this->request($credential, 'patch', "/files/{$fileId}", ['trashed' => false]);
    }

    /**
     * Reads a file's metadata. Used by the health check to prove the root
     * folder exists AND is reachable by this grant — the two things an
     * administrator most often gets wrong.
     *
     * A 404 here is read as a scope/ownership problem and not only as a wrong
     * id, because Drive answers the same way for both.
     *
     * @return array<string, mixed>
     *
     * @throws GoogleDriveException
     */
    public function getFile(
        DriveCredential $credential,
        string $fileId,
        string $fields = 'id,name,mimeType,trashed,ownedByMe,capabilities(canAddChildren)',
    ): array {
        return (array) $this->request($credential, 'get', "/files/{$fileId}", [
            'fields' => $fields,
        ])->json();
    }

    /**
     * Who this grant belongs to and how much room that account has left.
     *
     * This is the probe that replaced the old shared-drive check, and it
     * answers the two questions the previous storage model could not. WHO:
     * a refresh token carries no readable identity, so without asking there is
     * no way to tell that the token authorizes a personal Gmail account
     * different from the one whose folder id was pasted below it — a mismatch
     * that surfaces later as an unexplainable 404. HOW MUCH ROOM: the quota is
     * now a real, finite number that belongs to a human being, so an upload can
     * genuinely fail for lack of space, and saying so on the settings screen is
     * better than discovering it on the operator's first large file.
     *
     * `storageQuota.limit` is absent on unlimited plans, which is a valid
     * answer and not an error.
     *
     * @return array{email: string|null, name: string|null, limit: int|null, usage: int|null}
     *
     * @throws GoogleDriveException
     */
    public function about(DriveCredential $credential): array
    {
        $response = $this->request($credential, 'get', '/about', [
            'fields' => 'user(emailAddress,displayName),storageQuota(limit,usage)',
        ]);

        $limit = $response->json('storageQuota.limit');
        $usage = $response->json('storageQuota.usage');

        return [
            'email' => $response->json('user.emailAddress'),
            'name' => $response->json('user.displayName'),
            'limit' => is_numeric($limit) ? (int) $limit : null,
            'usage' => is_numeric($usage) ? (int) $usage : null,
        ];
    }

    /**
     * Folders this grant can actually reach right now.
     *
     * Diagnostic only, and deliberately so: it is the answer to the question an
     * administrator staring at a 404 really has — "does this token see ANY of
     * my folders?". An empty list means the grant was issued against a
     * different Google account than the one holding the folder (or the scope is
     * too narrow to observe it); a list that does not contain the configured id
     * means the id belongs to a different folder than the one that was meant.
     *
     * Returns an empty array on failure instead of throwing: a diagnostic that
     * can itself blow up would replace the real error with its own.
     *
     * @return array<int, array{id: string, name: string, owned: bool}>
     */
    public function listAccessibleFolders(DriveCredential $credential, int $limit = 10): array
    {
        try {
            $response = $this->request($credential, 'get', '/files', [
                'q' => "mimeType = 'application/vnd.google-apps.folder' and trashed = false",
                'fields' => 'files(id,name,ownedByMe)',
                'pageSize' => max(1, min($limit, 100)),
            ]);
        } catch (Throwable) {
            return [];
        }

        return collect((array) ($response->json('files') ?? []))
            ->map(fn ($folder) => [
                'id' => (string) ($folder['id'] ?? ''),
                'name' => (string) ($folder['name'] ?? ''),
                // A folder somebody else shared with this account still stores
                // its children against THEIR quota, so ownership is worth
                // stating next to a folder offered as a candidate root.
                'owned' => (bool) ($folder['ownedByMe'] ?? false),
            ])
            ->filter(fn (array $folder) => $folder['id'] !== '')
            ->values()
            ->all();
    }

    /**
     * One authenticated Drive API call.
     *
     * GET and DELETE carry their parameters in the query string; POST and
     * PATCH carry a JSON body plus an optional query. Centralizing that here
     * is what keeps every caller above free of transport concerns and gives
     * every failure the same shape.
     *
     * DELETE builds its query string by hand because the framework's `delete()`
     * puts its array in the request BODY as JSON while `get()` puts it in the
     * query string, and Drive ignores bodies on DELETE. Keeping the two
     * symmetrical here means a parameter added to a delete later actually
     * arrives.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     *
     * @throws GoogleDriveException
     */
    private function request(
        DriveCredential $credential,
        string $method,
        string $path,
        array $payload = [],
        array $query = [],
    ): Response {
        $url = rtrim(config('media.drive.api_base'), '/').$path;

        $client = $this->http(config('media.drive.timeout'))
            ->withToken($this->accessToken($credential));

        try {
            $response = match ($method) {
                'get' => $client->get($url, $payload + $query),
                'delete' => $client->delete($this->withQuery($url, $payload + $query)),
                default => $client->{$method}($this->withQuery($url, $query), $payload),
            };
        } catch (Throwable $e) {
            throw new GoogleDriveException(
                'Fallo de red al comunicarse con Google Drive: '.$e->getMessage(),
                null,
                ['path' => $path, 'method' => $method],
                $e,
            );
        }

        if ($response->failed()) {
            throw new GoogleDriveException(
                $this->describeFailure($response, 'Google Drive respondió con un error'),
                $response->status(),
                [
                    'path' => $path,
                    'method' => $method,
                    // Google's reason code is carried up untouched because it
                    // is often the entire diagnosis: `notFound` on a folder the
                    // administrator can see in their browser means the token
                    // belongs to another account, not that the id is wrong.
                    'reason' => $this->failureReason($response),
                ],
            );
        }

        return $response;
    }

    /**
     * Google's machine-readable reason code for a failed call, when it sent one.
     *
     * Both shapes are read: Drive answers `{error: {errors: [{reason}]}}` and
     * the OAuth token endpoint answers `{error: "invalid_grant"}`.
     */
    private function failureReason(Response $response): ?string
    {
        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $reason = $body['error']['errors'][0]['reason']
            ?? (is_string($body['error'] ?? null) ? $body['error'] : null);

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /**
     * Turns a Google error body into one readable line, preserving Google's
     * own words.
     *
     * Drive answers with `{error: {message, errors: [{reason}]}}` and the OAuth
     * endpoint with `{error, error_description}`. Both are read, because the
     * reason code is often the whole diagnosis: `invalid_grant` means the
     * refresh token was revoked or expired, and no paraphrase carries that.
     */
    private function describeFailure(Response $response, string $prefix): string
    {
        $body = $response->json();

        $message = is_array($body)
            ? ($body['error']['message'] ?? $body['error_description'] ?? (is_string($body['error'] ?? null) ? $body['error'] : null))
            : null;

        $reason = $this->failureReason($response);

        $detail = trim((string) ($message ?? substr($response->body(), 0, 300)));

        return $prefix.' (HTTP '.$response->status().')'
            .($reason ? " [{$reason}]" : '')
            .($detail !== '' ? ': '.$detail : '.');
    }

    /**
     * Base HTTP client with bounded network time.
     *
     * The bound is the point. A VPS whose egress to googleapis.com is filtered
     * DROPS the packets; without an explicit timeout the socket waits for PHP's
     * default and the operator watches a spinner for a minute before getting
     * nothing. A few seconds ending in a readable error is strictly better.
     */
    private function http(int|null $timeout = null): PendingRequest
    {
        return Http::timeout($timeout ?? (int) config('media.drive.timeout', 20))
            ->connectTimeout((int) config('media.drive.connect_timeout', 8))
            ->acceptJson();
    }

    /** A URL with the given parameters appended, or the URL unchanged. */
    private function withQuery(string $url, array $query): string
    {
        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }
}
