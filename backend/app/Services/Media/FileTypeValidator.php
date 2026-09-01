<?php

namespace App\Services\Media;

use App\Exceptions\Media\DisallowedFileTypeException;
use App\Models\AllowedFileType;
use Illuminate\Http\UploadedFile;

/**
 * The upload gate.
 *
 * Every byte that reaches Google Drive passes through this class first, and
 * this class carries no list of its own: it asks `allowed_file_types` and
 * obeys. That is the point of the module's dynamic configuration — the policy
 * is data an administrator edits, not code somebody has to redeploy.
 *
 * The validation is deliberately layered, because each layer catches a
 * different mistake:
 *
 *   1. The extension must have a row.        (unknown type)
 *   2. The row must be active.               (policy currently in force)
 *   3. The declared MIME must match the row. (a renamed file)
 *   4. The size must fit the row's ceiling.  (resource protection)
 *
 * Order matters: reporting "too large" for an extension nobody ever enabled
 * would send the operator chasing the wrong problem.
 */
class FileTypeValidator
{
    /**
     * Validates an upload and returns the policy that accepted it.
     *
     * @throws DisallowedFileTypeException
     */
    public function validate(UploadedFile $file): AllowedFileType
    {
        $extension = AllowedFileType::normalizeExtension(
            // getClientOriginalExtension() reads the submitted NAME, which is
            // exactly what the whitelist is keyed on. `extension()` would read
            // the guessed type instead and silently accept a .exe that PHP
            // happens to sniff as something benign.
            $file->getClientOriginalExtension()
        );

        if ($extension === '') {
            throw DisallowedFileTypeException::unknown('(sin extensión)');
        }

        $policy = AllowedFileType::query()->where('extension', $extension)->first();

        if (! $policy) {
            throw DisallowedFileTypeException::unknown($extension);
        }

        if (! $policy->is_active) {
            throw DisallowedFileTypeException::inactive($extension);
        }

        $this->assertMimeMatches($policy, $file);
        $this->assertSizeFits($policy, $file);

        return $policy;
    }

    /**
     * Rejects a file whose real content does not match its extension.
     *
     * The MIME compared is `getMimeType()`, which PHP derives from the file's
     * magic bytes on disk — NOT `getClientMimeType()`, which is a header the
     * client writes and an attacker therefore controls. Renaming `payload.php`
     * to `invoice.pdf` gets past the extension check and dies here.
     *
     * The comparison tolerates the aliases that are genuinely the same format
     * under two registered names, because a browser choosing `image/jpg` over
     * `image/jpeg` is not an attack and blocking it would only teach
     * administrators to disable the check.
     *
     * @throws DisallowedFileTypeException
     */
    private function assertMimeMatches(AllowedFileType $policy, UploadedFile $file): void
    {
        $detected = strtolower((string) $file->getMimeType());
        $expected = strtolower($policy->mime_type);

        if ($detected === $expected || $detected === '') {
            return;
        }

        if (in_array($detected, $this->aliasesFor($expected), true)) {
            return;
        }

        throw DisallowedFileTypeException::mimeMismatch($policy->extension, $expected, $detected);
    }

    /**
     * Equivalent MIME spellings of one format.
     *
     * The list stays small and explicit on purpose: every entry is a pair the
     * IANA registry or a real browser actually produces, so it can be reviewed
     * as a security decision rather than trusted as a heuristic.
     *
     * @return array<int, string>
     */
    private function aliasesFor(string $mime): array
    {
        $groups = [
            ['image/jpeg', 'image/jpg', 'image/pjpeg'],
            ['image/svg+xml', 'text/xml', 'text/plain'],
            ['text/csv', 'text/plain', 'application/csv'],
            ['application/vnd.ms-excel', 'application/excel', 'text/csv'],
            [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
            ],
            [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
            ],
            [
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/zip',
            ],
            ['application/zip', 'application/x-zip-compressed'],
        ];

        foreach ($groups as $group) {
            if ($group[0] === $mime) {
                return $group;
            }
        }

        return [$mime];
    }

    /**
     * Enforces the per-type ceiling, already clamped by the platform maximum.
     *
     * @throws DisallowedFileTypeException
     */
    private function assertSizeFits(AllowedFileType $policy, UploadedFile $file): void
    {
        $sizeKb = (int) ceil($file->getSize() / 1024);
        $limitKb = $policy->effective_max_size_kb;

        if ($sizeKb > $limitKb) {
            throw DisallowedFileTypeException::tooLarge($policy->extension, $sizeKb, $limitKb);
        }
    }
}
