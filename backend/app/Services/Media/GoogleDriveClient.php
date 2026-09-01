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
 * Native Google Drive v3 client driven by a database-held service account.
 *
 * WHY NOT google/apiclient. The surface this module needs is small — mint a
 * token, create a folder, upload a file, rewrite its permissions, read it back
 * — and the official SDK is built around credentials that live in a file on
 * disk or in an environment variable. This module's whole premise is the
 * opposite: the credential lives encrypted in the database and is rotated from
 * the admin panel. Speaking the REST API directly keeps that path under our
 * control and avoids bending an SDK into a shape it does not want.
 *
 * AUTHENTICATION. Service accounts do not use the interactive OAuth dance:
 * the application builds a JWT asserting "I am <client_email>, I want <scope>",
 * signs it with the account's RSA private key, and exchanges it for a
 * short-lived access token. The token is cached, keyed by credential row, so a
 * burst of uploads costs one token exchange rather than one per file.
 *
 * SCOPE. `drive.file` and not `drive`: the token may only touch objects this
 * application created. A token leaked from the cache cannot enumerate, read or
 * destroy the rest of the organization's Drive — which is the difference
 * between an incident and a catastrophe.
 */
class GoogleDriveClient
{
    /**
     * Access token for a credential row, minted on demand and cached until
     * shortly before it expires.
     *
     * The leeway matters: a token cached for its full hour can be handed to a
     * request that departs 200 ms before the expiry and arrives after it,
     * producing a 401 that no retry policy explains. Retiring it five minutes
     * early makes that race impossible.
     *
     * @throws GoogleDriveException when Google refuses the assertion.
     */
    public function accessToken(DriveCredential $credential): string
    {
        $cacheKey = DriveCredential::TOKEN_CACHE_PREFIX.$credential->id;

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $ttl = (int) config('media.drive.token_ttl', 3600);
        $leeway = (int) config('media.drive.token_leeway', 300);

        $token = $this->exchangeAssertion($credential, $ttl);

        Cache::put($cacheKey, $token, max(60, $ttl - $leeway));

        return $token;
    }

    /**
     * Builds and signs the JWT assertion, then trades it for an access token.
     *
     * @throws GoogleDriveException
     */
    private function exchangeAssertion(DriveCredential $credential, int $ttl): string
    {
        $assertion = $this->buildAssertion($credential, $ttl);

        try {
            $response = $this->http(config('media.drive.timeout'))
                ->asForm()
                ->post(config('media.drive.token_endpoint'), [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);
        } catch (Throwable $e) {
            // A connection that never completed: blocked egress, DNS failure,
            // TLS rejected. Google said nothing, so there is no provider text
            // to preserve — the transport's own message is the diagnosis.
            throw new GoogleDriveException(
                'No se pudo contactar a Google para autenticar la cuenta de servicio: '.$e->getMessage(),
                null,
                ['stage' => 'token'],
                $e,
            );
        }

        if ($response->failed()) {
            throw new GoogleDriveException(
                $this->describeFailure($response, 'Google rechazó las credenciales de la Service Account'),
                $response->status(),
                ['stage' => 'token'],
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

        return $token;
    }

    /**
     * RS256-signed JWT asserting the service account's identity.
     *
     * `iat` is backdated by ten seconds deliberately. Google rejects an
     * assertion issued in its future, and a container whose clock drifts a few
     * seconds ahead of Google's would otherwise fail every single upload with
     * an opaque "invalid_grant" — one of the hardest failures in this
     * integration to diagnose from the symptom.
     *
     * @throws GoogleDriveException when the stored key cannot sign.
     */
    private function buildAssertion(DriveCredential $credential, int $ttl): string
    {
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        $claims = [
            'iss' => $credential->client_email,
            'scope' => config('media.drive.scope'),
            'aud' => config('media.drive.token_endpoint'),
            'iat' => $now - 10,
            'exp' => $now + $ttl,
        ];

        $payload = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES))
            .'.'
            .$this->base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));

        $key = openssl_pkey_get_private($this->normalizePrivateKey((string) $credential->private_key));

        if ($key === false) {
            throw new GoogleDriveException(
                'La llave privada almacenada no es un PEM RSA válido. Vuelve a cargar el JSON '
                    .'de la Service Account tal como lo descargaste de Google Cloud.',
                null,
                ['stage' => 'sign'],
            );
        }

        $signature = '';

        if (! openssl_sign($payload, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new GoogleDriveException(
                'No se pudo firmar la aserción JWT con la llave privada almacenada.',
                null,
                ['stage' => 'sign'],
            );
        }

        return $payload.'.'.$this->base64UrlEncode($signature);
    }

    /**
     * Repairs the two ways a service account key survives a copy-paste badly.
     *
     * Pasting the JSON into a form and reading it back through a shell or a
     * spreadsheet turns the literal two-character sequence `\n` into the key's
     * only line breaks, and some clients normalize the newlines to CRLF.
     * OpenSSL accepts neither. Fixing it here — once, at the boundary — beats
     * telling an administrator their valid key is invalid.
     */
    private function normalizePrivateKey(string $key): string
    {
        return str_replace(["\\n", "\r\n"], ["\n", "\n"], trim($key));
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
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
        ]);

        $found = $existing->json('files.0.id');

        if (is_string($found) && $found !== '') {
            return $found;
        }

        $created = $this->request($credential, 'post', '/files', [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents' => [$parentId],
        ], ['fields' => 'id', 'supportsAllDrives' => 'true']);

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
                    'supportsAllDrives' => 'true',
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
                ['stage' => 'upload'],
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
            ['supportsAllDrives' => 'true', 'fields' => 'id,name,mimeType,size'],
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
            'supportsAllDrives' => 'true',
        ]);

        return (array) ($response->json('permissions') ?? []);
    }

    /** @throws GoogleDriveException */
    public function deletePermission(DriveCredential $credential, string $fileId, string $permissionId): void
    {
        $this->request($credential, 'delete', "/files/{$fileId}/permissions/{$permissionId}", [], [
            'supportsAllDrives' => 'true',
        ]);
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
        ], [
            'supportsAllDrives' => 'true',
            'sendNotificationEmail' => 'false',
        ]);
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
                ->get(rtrim(config('media.drive.api_base'), '/')."/files/{$fileId}", [
                    'alt' => 'media',
                    'supportsAllDrives' => 'true',
                ]);
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
                ['stage' => 'download'],
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
        $this->request($credential, 'patch', "/files/{$fileId}", ['trashed' => true], [
            'supportsAllDrives' => 'true',
        ]);
    }

    /** @throws GoogleDriveException */
    public function untrashFile(DriveCredential $credential, string $fileId): void
    {
        $this->request($credential, 'patch', "/files/{$fileId}", ['trashed' => false], [
            'supportsAllDrives' => 'true',
        ]);
    }

    /**
     * Reads a file's metadata. Used by the health check to prove the root
     * folder exists AND is reachable by this account — the two things an
     * administrator most often gets wrong.
     *
     * @return array<string, mixed>
     *
     * @throws GoogleDriveException
     */
    public function getFile(DriveCredential $credential, string $fileId, string $fields = 'id,name,mimeType,trashed,capabilities(canAddChildren)'): array
    {
        return (array) $this->request($credential, 'get', "/files/{$fileId}", [
            'fields' => $fields,
            'supportsAllDrives' => 'true',
        ])->json();
    }

    /**
     * One authenticated Drive API call.
     *
     * GET and DELETE carry their parameters in the query string; POST and
     * PATCH carry a JSON body plus an optional query. Centralizing that here
     * is what keeps every caller above free of transport concerns and gives
     * every failure the same shape.
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
                'get', 'delete' => $client->{$method}($url, $payload + $query),
                default => $client->{$method}(
                    $query === [] ? $url : $url.'?'.http_build_query($query),
                    $payload,
                ),
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
                ['path' => $path, 'method' => $method],
            );
        }

        return $response;
    }

    /**
     * Turns a Google error body into one readable line, preserving Google's
     * own words.
     *
     * Drive answers with `{error: {message, errors: [{reason}]}}` and the OAuth
     * endpoint with `{error, error_description}`. Both are read, because the
     * reason code is often the whole diagnosis: `insufficientFilePermissions`
     * means the root folder was never shared with the service account, and no
     * paraphrase carries that.
     */
    private function describeFailure(Response $response, string $prefix): string
    {
        $body = $response->json();

        $message = is_array($body)
            ? ($body['error']['message'] ?? $body['error_description'] ?? (is_string($body['error'] ?? null) ? $body['error'] : null))
            : null;

        $reason = is_array($body) ? ($body['error']['errors'][0]['reason'] ?? null) : null;

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

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
