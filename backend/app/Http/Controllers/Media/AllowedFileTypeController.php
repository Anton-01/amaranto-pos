<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\StoreAllowedFileTypeRequest;
use App\Http\Requests\Media\UpdateAllowedFileTypeRequest;
use App\Models\AllowedFileType;
use App\Models\MediaAuditLog;
use App\Models\MediaFile;
use App\Services\Media\MediaAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administration of the dynamic upload whitelist.
 *
 * Every write here changes what the POS will accept on its next upload, with no
 * deploy and no cache to warm, so every write is audited.
 */
class AllowedFileTypeController extends Controller
{
    public function __construct(private readonly MediaAuditLogger $audit) {}

    /**
     * Full catalog, ordered so the enabled types an operator cares about come
     * first and the disabled ones sink to the bottom of their category.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AllowedFileType::with('updatedByUser:id,name');

        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }

        if ($request->filled('status')) {
            $query->where('is_active', $request->string('status')->toString() === 'active');
        }

        $types = $query
            ->orderBy('category')
            ->orderByDesc('is_active')
            ->orderBy('extension')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $types,
            'metadata' => [
                'total' => $types->count(),
                'active' => $types->where('is_active', true)->count(),
                // The ceiling is echoed on every list so the admin form can
                // cap its number input without a second request.
                'platform_max_kb' => (int) config('media.max_upload_kb', 25600),
            ],
        ]);
    }

    /** Catalogs consumed by the admin form (categories and the hard ceiling). */
    public function catalogs(): JsonResponse
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'categories' => collect(AllowedFileType::CATEGORIES)
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'platform_max_kb' => (int) config('media.max_upload_kb', 25600),
            ],
        ]);
    }

    public function store(StoreAllowedFileTypeRequest $request): JsonResponse
    {
        $type = AllowedFileType::create([
            ...$request->validated(),
            'is_active' => $request->boolean('is_active', true),
            'updated_by' => $request->user()->id,
        ]);

        $this->audit->recordDetached(
            MediaAuditLog::ACTION_FILE_TYPE_CREATED,
            '.'.$type->extension,
            [
                'allowed_file_type_id' => $type->id,
                'extension' => $type->extension,
                'mime_type' => $type->mime_type,
                'max_size_kb' => $type->max_size_kb,
                'category' => $type->category,
                'is_active' => $type->is_active,
            ],
            $request->user(),
        );

        return response()->json([
            'status' => 'success',
            'data' => $type->fresh('updatedByUser'),
            'metadata' => ['message' => 'Tipo de archivo registrado. Las subidas de .'.$type->extension
                .' quedan '.($type->is_active ? 'habilitadas' : 'bloqueadas').' de inmediato.'],
        ], 201);
    }

    public function update(UpdateAllowedFileTypeRequest $request, AllowedFileType $allowedFileType): JsonResponse
    {
        $before = $allowedFileType->only(['extension', 'mime_type', 'label', 'max_size_kb', 'category', 'is_active']);

        $allowedFileType->fill([...$request->validated(), 'updated_by' => $request->user()->id]);

        $changes = collect($allowedFileType->getDirty())
            ->except('updated_by')
            ->keys()
            ->mapWithKeys(fn (string $key) => [$key => ['from' => $before[$key] ?? null, 'to' => $allowedFileType->{$key}]])
            ->all();

        $allowedFileType->save();

        if ($changes !== []) {
            $this->audit->recordDetached(
                MediaAuditLog::ACTION_FILE_TYPE_UPDATED,
                '.'.$allowedFileType->extension,
                ['allowed_file_type_id' => $allowedFileType->id, 'changes' => $changes],
                $request->user(),
            );
        }

        return response()->json([
            'status' => 'success',
            'data' => $allowedFileType->fresh('updatedByUser'),
            'metadata' => ['message' => 'Tipo de archivo actualizado.'],
        ]);
    }

    /**
     * Inline kill switch of the admin table.
     *
     * Disabling takes effect on the very next upload: there is no cached copy
     * of the policy anywhere, the validator reads the row per request. That is
     * what makes this a usable incident-response control and not a setting.
     */
    public function toggleStatus(Request $request, AllowedFileType $allowedFileType): JsonResponse
    {
        $allowedFileType->update([
            'is_active' => ! $allowedFileType->is_active,
            'updated_by' => $request->user()->id,
        ]);

        $this->audit->recordDetached(
            MediaAuditLog::ACTION_FILE_TYPE_STATUS_CHANGE,
            '.'.$allowedFileType->extension,
            [
                'allowed_file_type_id' => $allowedFileType->id,
                'extension' => $allowedFileType->extension,
                'is_active' => $allowedFileType->is_active,
            ],
            $request->user(),
        );

        return response()->json([
            'status' => 'success',
            'data' => $allowedFileType->fresh('updatedByUser'),
            'metadata' => [
                'message' => $allowedFileType->is_active
                    ? 'Tipo .'.$allowedFileType->extension.' habilitado. Las subidas se aceptarán de inmediato.'
                    : 'Tipo .'.$allowedFileType->extension.' bloqueado. Las nuevas subidas se rechazarán de inmediato.',
            ],
        ]);
    }

    /**
     * Deletion, refused in the two cases where it would destroy meaning.
     *
     * A system row keeps the library able to accept at least the basics; a row
     * with files already stored is the only remaining explanation of what those
     * files' policy was. Both cases point the administrator at the kill switch,
     * which achieves the operational goal without losing the record.
     */
    public function destroy(Request $request, AllowedFileType $allowedFileType): JsonResponse
    {
        if ($allowedFileType->is_system) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_MEDIA_TYPE_IS_SYSTEM',
                'message' => 'Este tipo es parte del catálogo base del sistema. '
                    .'Desactívalo en lugar de eliminarlo.',
            ], 422);
        }

        $inUse = MediaFile::withTrashed()->where('extension', $allowedFileType->extension)->count();

        if ($inUse > 0) {
            return response()->json([
                'status' => 'error',
                'code' => 'ERR_MEDIA_TYPE_IN_USE',
                'message' => "No se puede eliminar: hay {$inUse} archivo(s) en la biblioteca con esta extensión. "
                    .'Desactiva el tipo para impedir nuevas subidas sin perder la trazabilidad.',
            ], 422);
        }

        $extension = $allowedFileType->extension;
        $id = $allowedFileType->id;

        $allowedFileType->delete();

        $this->audit->recordDetached(
            MediaAuditLog::ACTION_FILE_TYPE_DELETED,
            '.'.$extension,
            ['allowed_file_type_id' => $id, 'extension' => $extension],
            $request->user(),
        );

        return response()->json([
            'status' => 'success',
            'metadata' => ['message' => 'Tipo de archivo eliminado del catálogo.'],
        ]);
    }
}
