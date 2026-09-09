<?php

namespace App\Services\Social\Contracts;

use App\Models\SocialAccount;

/**
 * One network's publishing behaviour, seen from the job.
 *
 * The contract exists so a new network is a new class and a new catalog entry
 * rather than another branch inside the job. It is deliberately narrow: the
 * publisher receives a resolved connection, a URL Meta's or anyone's crawler
 * can fetch, and a caption, and it answers with what the provider created. It
 * does not know about `social_posts`, about queues, or about the media library
 * — the job owns all three, and a publisher that also wrote the log would make
 * every implementation responsible for the audit trail.
 *
 * WHAT AN IMPLEMENTATION MUST GUARANTEE. `publish()` either returns a result
 * describing an object that now exists at the provider, or throws. Returning a
 * success for a publication that did not happen is the one failure mode this
 * module cannot detect afterwards.
 */
interface SocialChannelPublisher
{
    /** The `social_accounts.provider` value this publisher serves. */
    public function provider(): string;

    /**
     * Publishes one photo with its caption.
     *
     * @return array{id: string|null, context: array<string, mixed>}
     *
     * @throws \RuntimeException when the provider rejects the publication.
     */
    public function publish(SocialAccount $account, string $imageUrl, string $caption): array;
}
