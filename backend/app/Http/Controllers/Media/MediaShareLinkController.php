<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\StoreMediaShareLinkRequest;
use App\Models\MediaFile;
use App\Models\MediaShareLink;
use App\Services\Media\ShareLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Issue and revocation of controlled share links.
 */
class MediaShareLinkController extends Controller
{
    public function __construct(private readonly ShareLinkService $shareLinks) {}

    /** Every link ever issued for a file, live ones and dead ones alike. */
    public function index(MediaFile $mediaFile): JsonResponse
    {
        $links = $mediaFile->shareLinks()
            ->with(['createdByUser:id,name', 'revokedByUser:id,name'])
            ->latest()
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $links,
            'metadata' => [
                'active' => $links->where('is_usable', true)->count(),
                'expiration_options' => collect(config('media.share_links.expiration_options', []))
                    ->map(fn (int $hours) => [
                        'value' => $hours,
                        'label' => $hours < 24
                            ? $hours.' hora'.($hours === 1 ? '' : 's')
                            : ($hours / 24).' día'.($hours === 24 ? '' : 's'),
                    ])
                    ->values(),
                'permissions' => collect(MediaShareLink::PERMISSIONS)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
            ],
        ]);
    }

    /**
     * Mints a link.
     *
     * The raw token travels in THIS response and nowhere else, ever: the
     * database holds only its SHA-256. The UI must therefore surface the URL
     * immediately and tell the operator it cannot be shown again — which is the
     * same contract as an API key, and for the same reason.
     */
    public function store(StoreMediaShareLinkRequest $request, MediaFile $mediaFile): JsonResponse
    {
        // An archived file has no business acquiring new links: archiving
        // revokes the existing ones, and letting the next click re-open access
        // would make that revocation meaningless.
        if (! $mediaFile->is_active) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_MEDIA_FILE_ARCHIVED',
                'message' => 'El archivo está archivado. Reactívalo antes de generar enlaces de compartición.',
            ], 422);
        }

        $issued = $this->shareLinks->issue(
            $mediaFile,
            $request->user(),
            (int) $request->integer('expires_in_hours'),
            $request->string('permission')->toString(),
            $request->input('max_downloads') !== null ? (int) $request->integer('max_downloads') : null,
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'link' => $issued['link']->load('createdByUser:id,name'),
                'url' => $issued['url'],
            ],
            'metadata' => [
                'message' => 'Enlace generado. Cópialo ahora: por seguridad no vuelve a mostrarse.',
                'expires_at' => $issued['link']->expires_at?->toIso8601String(),
            ],
        ], 201);
    }

    /** Revokes a link. The row survives as evidence; it is never deleted. */
    public function destroy(Request $request, MediaFile $mediaFile, MediaShareLink $shareLink): JsonResponse
    {
        if ($shareLink->media_file_id !== $mediaFile->id) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_MEDIA_SHARE_LINK_MISMATCH',
                'message' => 'El enlace no pertenece a este archivo.',
            ], 404);
        }

        $revoked = $this->shareLinks->revoke($shareLink, $request->user());

        return response()->json([
            'status' => 'success',
            'data' => $revoked,
            'metadata' => ['message' => 'Enlace revocado. Deja de funcionar de inmediato.'],
        ]);
    }
}
