<?php

namespace App\Http\Controllers\Media;

use App\Exceptions\Media\DriveCredentialsMissingException;
use App\Exceptions\Media\GoogleDriveException;
use App\Http\Controllers\Controller;
use App\Models\DriveCredential;
use App\Models\MediaShareLink;
use App\Services\Media\GoogleDriveService;
use App\Services\Media\MediaAuditLogger;
use App\Services\Media\ShareLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only unauthenticated endpoint of the media module.
 *
 * It is what makes the module's central security decision possible: because
 * the POS serves shared bytes itself, the object in Google Drive never needs an
 * "anyone with the link" grant. Expiration, download caps and instant
 * revocation are enforced here, on every single request, against the database —
 * none of which Drive's public link can do.
 *
 * TWO PROPERTIES THIS CONTROLLER MUST KEEP:
 *
 * 1. Every failure answers exactly the same 404. Distinguishing "expired" from
 *    "never existed" confirms the existence of a resource to somebody who was
 *    only guessing at tokens.
 * 2. Nothing about the file leaks before the token is validated — not its name,
 *    not its size, not its existence.
 */
class PublicMediaShareController extends Controller
{
    public function __construct(
        private readonly ShareLinkService $shareLinks,
        private readonly GoogleDriveService $drive,
        private readonly MediaAuditLogger $audit,
    ) {}

    /**
     * Metadata of a shared file, for the recipient's landing view.
     *
     * Answers with the minimum a preview needs — name, type, size, how the file
     * should be rendered — and never with the uploader, the internal id, the
     * Drive id or the description.
     */
    public function show(string $token): JsonResponse
    {
        $link = $this->shareLinks->resolve($token);

        if (! $link) {
            return $this->notFound();
        }

        $file = $link->mediaFile;

        return response()->json([
            'status' => 'success',
            'data' => [
                'name' => $file->name,
                'extension' => $file->extension,
                'mime_type' => $file->mime_type,
                'category' => $file->category,
                'human_size' => $file->human_size,
                'preview_kind' => $file->preview_kind,
                'dimensions' => $file->dimensions,
                'permission' => $link->permission,
                'expires_at' => $link->expires_at?->toIso8601String(),
                'downloads_remaining' => $link->max_downloads !== null
                    ? max(0, $link->max_downloads - $link->download_count)
                    : null,
            ],
        ]);
    }

    /**
     * Streams the shared bytes.
     *
     * The access is booked BEFORE the bytes are fetched, so a Drive failure
     * mid-transfer cannot leave a redemption uncounted against a capped link —
     * the safe direction to err in when the counter is a security control.
     */
    public function download(Request $request, string $token): StreamedResponse|JsonResponse
    {
        $link = $this->shareLinks->resolve($token);

        if (! $link) {
            return $this->notFound();
        }

        // A view-only link never produces an attachment, whatever the query
        // string asks for: the permission is the grant, the parameter is a
        // preference.
        $isDownload = $link->permission === MediaShareLink::PERMISSION_DOWNLOAD
            && $request->boolean('download');

        $this->shareLinks->registerAccess($link, (string) $request->ip());

        try {
            $contents = $this->drive->download(
                DriveCredential::requireActive(),
                (string) $link->mediaFile->drive_file_id,
            );
        } catch (DriveCredentialsMissingException|GoogleDriveException $e) {
            // The recipient is an outsider: they get a generic unavailability,
            // never the state of our Drive integration. The diagnosis lives in
            // the audit trail, where the administrator can reach it.
            $this->audit->recordAnonymous('share_link_accessed', $link->mediaFile, [
                'share_link_id' => $link->id,
                'failed' => true,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 'ERR_MEDIA_SHARE_UNAVAILABLE',
                'message' => 'El recurso no está disponible en este momento.',
            ], 503);
        }

        return app(MediaLibraryController::class)->streamed($contents, $link->mediaFile, $isDownload);
    }

    /**
     * The single answer for every failure mode: unknown token, expired,
     * revoked, exhausted, or file archived. See the class docblock.
     */
    private function notFound(): JsonResponse
    {
        return response()->json([
            'status' => 'error',
            'code' => 'ERR_MEDIA_SHARE_LINK_INVALID',
            'message' => 'El enlace no existe, expiró o fue revocado.',
        ], 404);
    }
}
