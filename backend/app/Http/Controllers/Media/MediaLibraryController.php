<?php

namespace App\Http\Controllers\Media;

use App\Exceptions\Media\DisallowedFileTypeException;
use App\Exceptions\Media\DriveCredentialsMissingException;
use App\Exceptions\Media\GoogleDriveException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Media\StoreMediaFileRequest;
use App\Http\Requests\Media\UpdateMediaFileRequest;
use App\Models\AllowedFileType;
use App\Models\MediaFile;
use App\Services\Media\MediaLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The media library's own endpoints.
 *
 * The controller holds no business rules: it translates HTTP into a call to
 * App\Services\Media\MediaLibraryService and the outcome back into the
 * corporate JSON envelope. What it does own is the mapping from the module's
 * exceptions to HTTP status codes, and that mapping is deliberate — see
 * store().
 */
class MediaLibraryController extends Controller
{
    public function __construct(private readonly MediaLibraryService $library) {}

    /**
     * Paginated library grid.
     *
     * Projects only the columns the grid renders, per the system's payload
     * standard: the description holds a paragraph per row and shipping it for a
     * hundred thumbnails nobody is reading is pure weight on the wire.
     */
    public function index(Request $request): JsonResponse
    {
        $query = MediaFile::query()
            ->select(MediaFile::gridColumns())
            ->with('uploadedByUser:id,name')
            ->withCount(['activeShareLinks as active_share_links_count'])
            ->search($request->string('search')->toString());

        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }

        if ($request->filled('extension')) {
            $query->where('extension', AllowedFileType::normalizeExtension($request->string('extension')->toString()));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->string('status')->toString() === 'active');
        }

        $files = $query
            ->orderByDesc('created_at')
            ->paginate(min((int) $request->integer('per_page', 24), 100));

        return response()->json([
            'status' => 'success',
            'data' => $files->items(),
            'metadata' => [
                'pagination' => [
                    'current_page' => $files->currentPage(),
                    'last_page' => $files->lastPage(),
                    'per_page' => $files->perPage(),
                    'total' => $files->total(),
                ],
                // The filter bar is built from the types actually present in
                // the library, not from the whole whitelist: offering a filter
                // that can only ever return zero rows is noise.
                'categories' => MediaFile::query()
                    ->select('category')
                    ->selectRaw('count(*) as total')
                    ->groupBy('category')
                    ->pluck('total', 'category'),
            ],
        ]);
    }

    /** Full record, including the description and the share links. */
    public function show(MediaFile $mediaFile): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => $mediaFile->load([
                'uploadedByUser:id,name',
                'updatedByUser:id,name',
                'shareLinks' => fn ($q) => $q->with('createdByUser:id,name')->latest(),
            ]),
        ]);
    }

    /**
     * Uploads a file into the library.
     *
     * THE STATUS CODES ARE THE CONTRACT:
     *
     *  - 422 for a rejected type. The request was well formed; the file simply
     *    is not allowed by the policy in force. The response names the reason
     *    code so the UI can tell "not registered" (ask an administrator) from
     *    "disabled" (a decision is in effect) from "too large" (the user's to
     *    fix).
     *  - 503 when Drive is not configured. Nothing about the request is wrong
     *    and retrying it unchanged will work the moment an administrator
     *    finishes the setup — which is exactly what 503 means.
     *  - 502 when Google itself failed. The POS is a gateway to Drive here, and
     *    the upstream refused; the provider's own words are passed through.
     */
    public function store(StoreMediaFileRequest $request): JsonResponse
    {
        try {
            $media = $this->library->upload($request->file('file'), $request->user(), [
                'name' => $request->input('name'),
                'alt_text' => $request->input('alt_text'),
                'description' => $request->input('description'),
            ]);
        } catch (DisallowedFileTypeException $e) {
            return response()->json([
                'status' => 'error',
                'code' => $e->reason,
                'message' => $e->getMessage(),
                'data' => $e->context,
            ], 422);
        } catch (DriveCredentialsMissingException $e) {
            return response()->json([
                'status' => 'error',
                'code' => DriveCredentialsMissingException::ERROR_CODE,
                'message' => $e->getMessage(),
            ], 503);
        } catch (GoogleDriveException $e) {
            Log::error('Fallo al subir un archivo a Google Drive.', [
                'user_id' => $request->user()->id,
                'status_code' => $e->statusCode,
                'context' => $e->context,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => 'error',
                'code' => GoogleDriveException::ERROR_CODE,
                'message' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'status' => 'success',
            'data' => $media->fresh('uploadedByUser:id,name'),
            'metadata' => ['message' => 'Archivo subido y asegurado en Google Drive.'],
        ], 201);
    }

    public function update(UpdateMediaFileRequest $request, MediaFile $mediaFile): JsonResponse
    {
        $updated = $this->library->updateMetadata($mediaFile, $request->user(), $request->validated());

        return response()->json([
            'status' => 'success',
            'data' => $updated,
            'metadata' => ['message' => 'Metadatos actualizados.'],
        ]);
    }

    public function toggleStatus(Request $request, MediaFile $mediaFile): JsonResponse
    {
        $updated = $this->library->toggleStatus($mediaFile, $request->user());

        return response()->json([
            'status' => 'success',
            'data' => $updated,
            'metadata' => [
                'message' => $updated->is_active
                    ? 'Archivo reactivado en la biblioteca.'
                    : 'Archivo archivado. Sus enlaces compartidos fueron revocados.',
            ],
        ]);
    }

    public function destroy(Request $request, MediaFile $mediaFile): JsonResponse
    {
        $this->library->delete($mediaFile, $request->user(), $request->input('reason'));

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Archivo eliminado. Puedes recuperarlo desde la Papelera.'],
        ]);
    }

    /**
     * Streams the bytes for an authenticated operator.
     *
     * `?download=1` switches the disposition from inline to attachment and,
     * more importantly, changes which action the audit trail records: opening a
     * thumbnail and pulling a copy of a payroll spreadsheet are not the same
     * event.
     *
     * The response is streamed rather than returned as a string so the file
     * does not sit twice in memory (once as Drive's answer, once as the
     * response body).
     */
    public function content(Request $request, MediaFile $mediaFile): StreamedResponse|JsonResponse
    {
        $isDownload = $request->boolean('download');

        try {
            $contents = $this->library->fetchContents($mediaFile, $request->user(), $isDownload);
        } catch (DriveCredentialsMissingException $e) {
            return response()->json([
                'status' => 'error',
                'code' => DriveCredentialsMissingException::ERROR_CODE,
                'message' => $e->getMessage(),
            ], 503);
        } catch (GoogleDriveException $e) {
            return response()->json([
                'status' => 'error',
                'code' => GoogleDriveException::ERROR_CODE,
                'message' => $e->getMessage(),
            ], 502);
        }

        return $this->streamed($contents, $mediaFile, $isDownload);
    }

    /**
     * Re-applies the Drive privacy contract to one file.
     *
     * Exposed as an explicit action because permissions can be changed from
     * outside the POS: somebody with folder access clicks "share" in the Drive
     * UI and the object silently becomes readable by anyone with the link. This
     * is the control that takes it back, and it reports how many grants it had
     * to remove — a non-zero count is an incident, not a routine result.
     */
    public function reapplyPermissions(Request $request, MediaFile $mediaFile): JsonResponse
    {
        try {
            $result = $this->library->reapplyPermissions($mediaFile, $request->user());
        } catch (DriveCredentialsMissingException $e) {
            return response()->json([
                'status' => 'error',
                'code' => DriveCredentialsMissingException::ERROR_CODE,
                'message' => $e->getMessage(),
            ], 503);
        } catch (GoogleDriveException $e) {
            return response()->json([
                'status' => 'error',
                'code' => GoogleDriveException::ERROR_CODE,
                'message' => $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'status' => 'success',
            'data' => $result,
            'metadata' => [
                'message' => $result['removed'] > 0
                    ? 'Se revocaron '.$result['removed'].' permiso(s) público(s) detectados en Drive.'
                    : 'El archivo ya estaba restringido. No se encontraron permisos públicos.',
            ],
        ]);
    }

    /**
     * Byte response shared by the authenticated and the public share endpoints.
     *
     * `X-Content-Type-Options: nosniff` is not decoration. These bytes came
     * from a user upload; without it a browser may sniff a file served as
     * `text/plain` into HTML and execute script in this application's origin.
     */
    public function streamed(string $contents, MediaFile $mediaFile, bool $isDownload): StreamedResponse
    {
        $disposition = $isDownload ? 'attachment' : 'inline';
        $filename = $mediaFile->name.'.'.$mediaFile->extension;

        return response()->stream(function () use ($contents) {
            echo $contents;
        }, 200, [
            'Content-Type' => $mediaFile->mime_type,
            'Content-Length' => (string) strlen($contents),
            'Content-Disposition' => $disposition.'; filename="'.addslashes($filename).'"',
            'X-Content-Type-Options' => 'nosniff',
            // Never cached by a shared proxy: these bytes are private and the
            // URL is authenticated per request.
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
