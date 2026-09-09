<?php

namespace App\Services\Social;

use App\Models\MediaFile;
use App\Models\MediaShareLink;
use App\Models\User;
use App\Services\Media\ShareLinkService;
use RuntimeException;

/**
 * Turns a private library image into a URL Meta's crawler can fetch.
 *
 * THE PROBLEM THIS SOLVES. The Content Publishing API takes no bytes: both the
 * Facebook `/photos` edge and the Instagram `/media` edge accept only
 * `image_url`, and Meta downloads it themselves. The library's files, however,
 * are deliberately private in Drive — the media module refuses to create an
 * "anyone with the link" grant anywhere, because such a link cannot expire,
 * cannot be counted and cannot be revoked.
 *
 * THE ANSWER IS THE MECHANISM THAT ALREADY EXISTS. `media_share_links` inverts
 * sharing: the POS mints a token, hands out a URL pointing at itself, and
 * streams the bytes only after validating that token. So a publication mints
 * one short-lived, VIEW-ONLY link and gives Meta that URL. Drive is never
 * touched, and the exposure is a database fact that can be revoked the instant
 * anything looks wrong.
 *
 * THE WINDOW IS THE SMALLEST THE CATALOG OFFERS — one hour by default. Meta
 * fetches within seconds and re-hosts the image on their own CDN, so the link
 * is dead weight afterwards. It is not revoked on the way out, though, and that
 * is deliberate: Instagram re-reads the container while it transcodes, and a
 * link revoked at the end of a successful call would break a publication that
 * had not finished being ingested.
 *
 * VIEW AND NOT DOWNLOAD. The crawler needs the bytes inline; the download
 * permission would additionally allow an attachment response, which nothing in
 * this flow wants and which turns a leaked URL into a file exfiltration path.
 */
class SocialImageUrlResolver
{
    public function __construct(private readonly ShareLinkService $shareLinks) {}

    /**
     * @return array{url: string, link: MediaShareLink}
     */
    public function forFile(MediaFile $file, User $actor): array
    {
        $this->assertPublishable($file);

        $issued = $this->shareLinks->issue(
            $file,
            $actor,
            $this->expirationHours(),
            MediaShareLink::PERMISSION_VIEW,
            // No download cap. Meta's crawler is not one request: the fetch,
            // the retry after a transient error and Instagram's re-read while
            // the container transcodes are three, and a link capped at one
            // would fail whichever of them arrived second.
            null,
        );

        return ['url' => $issued['url'], 'link' => $issued['link']];
    }

    /**
     * Rejects a file that cannot become a photo post, before any link exists.
     *
     * Every one of these would otherwise be discovered by Meta and reported as
     * a generic "unable to fetch", after the module had already published a URL
     * for a resource it should never have exposed.
     */
    private function assertPublishable(MediaFile $file): void
    {
        if (! str_starts_with((string) $file->mime_type, 'image/')) {
            throw new RuntimeException('Solo se pueden publicar imágenes en redes sociales.');
        }

        if (! $file->is_active) {
            throw new RuntimeException('El archivo está archivado. Reactívalo antes de publicarlo.');
        }

        if (blank($file->drive_file_id)) {
            throw new RuntimeException('El archivo no tiene bytes almacenados en Drive.');
        }
    }

    /**
     * The configured window, forced back into the closed catalog of allowed
     * expirations.
     *
     * That list is a security control of the media module, and this module does
     * not get to widen it through a misconfigured environment variable: an
     * unrecognised value falls back to the shortest option available rather
     * than being passed through.
     */
    private function expirationHours(): int
    {
        $configured = (int) config('social.image_link.expires_in_hours', 1);
        $allowed = (array) config('media.share_links.expiration_options', [1]);

        return in_array($configured, $allowed, true)
            ? $configured
            : (int) min($allowed);
    }
}
