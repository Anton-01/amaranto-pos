<?php

namespace App\Services\Media;

use App\Models\MediaAuditLog;
use App\Models\MediaFile;
use App\Models\MediaShareLink;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Issues, resolves and revokes the module's controlled share links.
 *
 * THE DESIGN DECISION THIS CLASS EXISTS TO ENFORCE. Google Drive can make a
 * file readable by "anyone with the link" in one API call, and that call is
 * never made anywhere in this module. A Drive public link cannot expire,
 * cannot be counted, cannot be revoked without touching the object's ACL, and
 * — worst of all — remains valid in every browser cache and chat history it was
 * ever pasted into.
 *
 * So the object in Drive stays private, and sharing is inverted: the POS mints
 * a token, hands out a URL pointing at ITSELF, and streams the bytes only
 * after validating that token. Expiration, download caps and instant
 * revocation become database facts, and every redemption lands in the audit
 * trail with its address.
 */
class ShareLinkService
{
    public function __construct(private readonly MediaAuditLogger $audit) {}

    /**
     * Mints a link and returns it together with its RAW token.
     *
     * The raw token is returned exactly once, here. What is persisted is its
     * SHA-256, so a reader of the database — a leaked dump, an over-privileged
     * support query — cannot rebuild a working URL. This is the same discipline
     * the framework applies to password reset tokens, and for the same reason.
     *
     * @return array{link: MediaShareLink, token: string, url: string}
     */
    public function issue(
        MediaFile $file,
        User $actor,
        int $expiresInHours,
        string $permission = MediaShareLink::PERMISSION_VIEW,
        ?int $maxDownloads = null,
    ): array {
        $token = $this->generateToken();

        $link = MediaShareLink::create([
            'media_file_id' => $file->id,
            'token_hash' => MediaShareLink::hashToken($token),
            // Enough to tell two links apart in the admin table, far too little
            // to guess the remaining 250-odd bits.
            'token_preview' => substr($token, 0, 12),
            'permission' => $permission,
            'expires_at' => Carbon::now()->addHours($expiresInHours),
            'max_downloads' => $maxDownloads,
            'download_count' => 0,
            'created_by' => $actor->id,
        ]);

        $this->audit->record(MediaAuditLog::ACTION_SHARE_LINK_CREATED, $file, [
            'share_link_id' => $link->id,
            'permission' => $permission,
            'expires_at' => $link->expires_at?->toIso8601String(),
            'expires_in_hours' => $expiresInHours,
            'max_downloads' => $maxDownloads,
        ], $actor);

        return [
            'link' => $link,
            'token' => $token,
            'url' => $this->urlFor($token),
        ];
    }

    /**
     * Resolves a raw token to a redeemable link, or null.
     *
     * Null covers every failure mode without distinguishing them, and that is
     * deliberate: telling a caller "this token exists but expired" as opposed
     * to "this token never existed" confirms the existence of a resource to
     * somebody who was only guessing. The public endpoint answers 404 for all
     * of them.
     */
    public function resolve(string $token): ?MediaShareLink
    {
        $link = MediaShareLink::query()
            ->with('mediaFile')
            ->where('token_hash', MediaShareLink::hashToken($token))
            ->first();

        if (! $link || ! $link->is_usable) {
            return null;
        }

        // A link outliving its file is not redeemable. The soft-deleted case
        // matters: sending a file to the trash must close its open links
        // immediately, not once they expire on their own.
        if (! $link->mediaFile || $link->mediaFile->trashed() || ! $link->mediaFile->is_active) {
            return null;
        }

        return $link;
    }

    /**
     * Books one redemption: bumps the counter and stamps the access.
     *
     * The increment runs as an atomic SQL update rather than a read-modify-write
     * on the model, so two simultaneous redemptions of a link capped at one
     * download cannot both read `download_count = 0` and both serve the file.
     */
    public function registerAccess(MediaShareLink $link, string $ipAddress): void
    {
        DB::table('media_share_links')
            ->where('id', $link->id)
            ->update([
                'download_count' => DB::raw('download_count + 1'),
                'last_accessed_at' => now(),
                'last_accessed_ip' => $ipAddress,
                'updated_at' => now(),
            ]);

        $this->audit->recordAnonymous(MediaAuditLog::ACTION_SHARE_LINK_ACCESSED, $link->mediaFile, [
            'share_link_id' => $link->id,
            'token_preview' => $link->token_preview,
            'permission' => $link->permission,
            'download_number' => $link->download_count + 1,
            'max_downloads' => $link->max_downloads,
        ]);
    }

    /**
     * Revokes a link. The row is never deleted — a revoked share is evidence
     * that a share once existed, and destroying it would destroy the record of
     * what was exposed and for how long.
     */
    public function revoke(MediaShareLink $link, User $actor): MediaShareLink
    {
        if ($link->revoked_at === null) {
            $link->update([
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
            ]);
        }

        $this->audit->record(MediaAuditLog::ACTION_SHARE_LINK_REVOKED, $link->mediaFile, [
            'share_link_id' => $link->id,
            'token_preview' => $link->token_preview,
            'downloads_served' => $link->download_count,
        ], $actor);

        return $link->fresh();
    }

    /**
     * Revokes every open link of a file in one statement.
     *
     * Called when a file is archived or deleted. Without it, an operator who
     * "removed" a compromising document would leave every link they had ever
     * shared still serving it.
     */
    public function revokeAllFor(MediaFile $file, User $actor): int
    {
        return MediaShareLink::query()
            ->where('media_file_id', $file->id)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'updated_at' => now(),
            ]);
    }

    /** Absolute URL a recipient opens. Points at this application, not Drive. */
    public function urlFor(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/api/media/shared/'.$token;
    }

    /**
     * 256 bits of CSPRNG entropy in a URL-safe alphabet.
     *
     * random_bytes() and not a uuid or a hash of predictable inputs: this
     * string is the only thing standing between an anonymous visitor and a
     * corporate document.
     */
    private function generateToken(): string
    {
        $bytes = (int) config('media.share_links.token_bytes', 32);

        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
