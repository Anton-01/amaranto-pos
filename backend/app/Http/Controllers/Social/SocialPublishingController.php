<?php

namespace App\Http\Controllers\Social;

use App\Http\Controllers\Controller;
use App\Http\Requests\Social\PublishSocialPostRequest;
use App\Models\MediaFile;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Social\SocialPublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Entry point of the Social Composer.
 *
 * IT ANSWERS 202, NEVER 200. Nothing has been published when this controller
 * returns: it has written the evidence and queued the work. Saying "Accepted"
 * rather than "OK" is the honest status, and the composer's copy follows it —
 * "Publicación en cola" and not "Publicado".
 */
class SocialPublishingController extends Controller
{
    public function __construct(private readonly SocialPublishingService $publishing) {}

    /**
     * What the composer needs before it can render: the channels, whether each
     * one is connected, and the caption ceilings it must count against.
     */
    public function catalogs(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'channels' => $this->publishing->channelStatus(),
                'statuses' => collect(SocialPost::STATUSES)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
            ],
        ]);
    }

    /**
     * Queues a publication of one library image.
     *
     * The channels the operator ticked are honoured as sent: a network without
     * credentials is NOT filtered out here. It is queued and fails with a row
     * naming the missing piece — which is the difference between an operator
     * learning that Instagram is not configured and an operator watching their
     * Instagram selection quietly disappear.
     */
    public function store(PublishSocialPostRequest $request, MediaFile $mediaFile): JsonResponse
    {
        if (! str_starts_with((string) $mediaFile->mime_type, 'image/')) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_SOCIAL_NOT_AN_IMAGE',
                'message' => 'Solo se pueden publicar imágenes en redes sociales.',
            ], 422);
        }

        if (! $mediaFile->is_active) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_SOCIAL_FILE_ARCHIVED',
                'message' => 'El archivo está archivado. Reactívalo antes de publicarlo.',
            ], 422);
        }

        $rows = $this->publishing->queue(
            $mediaFile,
            $request->user(),
            (string) $request->input('caption', ''),
            (array) $request->input('channels', []),
        );

        return response()->json([
            'status' => 'success',
            'data' => $rows,
            'metadata' => [
                'batch_id' => $rows->first()?->batch_id,
                'message' => 'Publicación en cola. El resultado de cada red aparecerá en la bitácora.',
            ],
        ], 202);
    }

    /**
     * The publication log, served page by page.
     *
     * Ordered newest first and projected to the columns the table renders: the
     * caption of every post in a year of history is a paragraph per row nobody
     * is reading, and the payload standard forbids shipping it.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['nullable', Rule::in(array_keys(SocialAccount::PROVIDERS))],
            'status' => ['nullable', Rule::in(array_keys(SocialPost::STATUSES))],
            'media_file_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $posts = SocialPost::query()
            ->select([
                'id', 'batch_id', 'media_file_id', 'provider', 'status',
                'api_response_id', 'metadata', 'error_message', 'error_code',
                'published_at', 'created_by', 'created_at',
            ])
            ->with(['mediaFile:id,name,extension', 'createdByUser:id,name'])
            ->when($validated['provider'] ?? null, fn ($q, $provider) => $q->where('provider', $provider))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['media_file_id'] ?? null, fn ($q, $id) => $q->where('media_file_id', $id))
            ->latest('created_at')
            ->paginate($validated['per_page'] ?? (int) config('social.log.page_size', 25));

        return response()->json([
            'status' => 'success',
            'data' => $posts->items(),
            'metadata' => [
                'total' => $posts->total(),
                'per_page' => $posts->perPage(),
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
            ],
        ]);
    }

    /**
     * Recent history of one file, for the composer's footer.
     *
     * Short by design: the composer shows it to answer "has this image already
     * gone out, and how did it go", which the last handful of rows settles.
     */
    public function history(MediaFile $mediaFile): JsonResponse
    {
        $posts = SocialPost::query()
            ->select([
                'id', 'batch_id', 'media_file_id', 'provider', 'status',
                'api_response_id', 'metadata', 'error_message', 'published_at', 'created_at',
            ])
            ->where('media_file_id', $mediaFile->id)
            ->latest('created_at')
            ->limit((int) config('social.log.per_file_limit', 10))
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $posts,
        ]);
    }
}
