<?php

namespace Database\Seeders;

use App\Models\AllowedFileType;
use Illuminate\Database\Seeder;

/**
 * Base catalog of the dynamic upload whitelist.
 *
 * These rows are marked `is_system` so they cannot be deleted, only disabled:
 * a library that has lost the ability to accept a PNG is broken in a way that
 * is hard to diagnose from the symptom, and one careless click should not be
 * able to produce it.
 *
 * The limits are deliberately conservative. Widening one is a two-field edit
 * in the admin panel; recovering from a library where somebody uploaded a
 * 200 MB scan is not.
 *
 * Idempotent by extension, so re-running the seeder on an installation that
 * has already tuned its limits does not overwrite them.
 */
class AllowedFileTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // Images — the bulk of a POS library (product photos, logos).
            ['png', 'image/png', 'Imagen PNG', 4096, AllowedFileType::CATEGORY_IMAGE],
            ['jpg', 'image/jpeg', 'Imagen JPEG', 4096, AllowedFileType::CATEGORY_IMAGE],
            ['jpeg', 'image/jpeg', 'Imagen JPEG', 4096, AllowedFileType::CATEGORY_IMAGE],
            ['webp', 'image/webp', 'Imagen WebP', 4096, AllowedFileType::CATEGORY_IMAGE],

            /*
             * SVG ships DISABLED on purpose. It is a document format, not an
             * image one: an SVG can carry <script>, and a browser rendering it
             * from this application's origin executes that script. Enabling it
             * must be a deliberate decision by an administrator who knows the
             * trade-off, not a default nobody reviewed.
             */
            ['svg', 'image/svg+xml', 'Imagen vectorial SVG', 1024, AllowedFileType::CATEGORY_IMAGE, false],

            // Documents.
            ['pdf', 'application/pdf', 'Documento PDF', 10240, AllowedFileType::CATEGORY_DOCUMENT],
            [
                'docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Documento Word',
                10240,
                AllowedFileType::CATEGORY_DOCUMENT,
            ],
            ['txt', 'text/plain', 'Texto plano', 512, AllowedFileType::CATEGORY_DOCUMENT],

            // Spreadsheets — invoices, inventories, supplier price lists.
            [
                'xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Hoja de cálculo Excel',
                10240,
                AllowedFileType::CATEGORY_SPREADSHEET,
            ],
            ['xls', 'application/vnd.ms-excel', 'Hoja de cálculo Excel (legado)', 10240, AllowedFileType::CATEGORY_SPREADSHEET],
            ['csv', 'text/csv', 'Valores separados por comas', 5120, AllowedFileType::CATEGORY_SPREADSHEET],

            // Presentations.
            [
                'pptx',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'Presentación PowerPoint',
                20480,
                AllowedFileType::CATEGORY_PRESENTATION,
            ],

            /*
             * ZIP also ships DISABLED. The module never expands an archive, so
             * its contents bypass the whitelist entirely: a .zip is a hole
             * straight through the file type policy, and it should only be open
             * when somebody has decided it must be.
             */
            ['zip', 'application/zip', 'Archivo comprimido ZIP', 20480, AllowedFileType::CATEGORY_ARCHIVE, false],
        ];

        foreach ($types as $type) {
            [$extension, $mime, $label, $maxKb, $category] = $type;
            $isActive = $type[5] ?? true;

            AllowedFileType::firstOrCreate(
                ['extension' => $extension],
                [
                    'mime_type' => $mime,
                    'label' => $label,
                    'max_size_kb' => $maxKb,
                    'category' => $category,
                    'is_active' => $isActive,
                    'is_system' => true,
                ],
            );
        }
    }
}
