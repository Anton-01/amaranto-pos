<?php

/*
|--------------------------------------------------------------------------
| Modulo de Publicacion en Redes Sociales
|--------------------------------------------------------------------------
|
| Policy of the publishing module. Same split as the media module: everything
| that governs BEHAVIOUR lives here, and everything that governs WHO WE ARE
| against Meta lives encrypted in `social_accounts`.
|
| An operator must be able to rotate a Page token or point the module at a
| different Instagram account without a redeploy, and must NOT be able to widen
| the timeouts or the caption ceilings from the browser.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Meta Graph API
    |--------------------------------------------------------------------------
    |
    | The module speaks Graph directly over the framework's HTTP client, for
    | the same reason the media module speaks Drive directly: the surface it
    | needs is three endpoints, and the credential lives in the database rather
    | than in a file the official SDK expects to find on disk.
    |
    | THE VERSION IS PINNED, AND IT MUST BE. An unversioned Graph call is served
    | by whatever version Meta considers current, which changes under you: the
    | photo publishing flow used here is stable from v18.0 onward, and a silent
    | jump is how a working integration breaks on a morning nobody deployed
    | anything. Meta retires a version roughly two years after release, so this
    | value is a maintenance date, not a constant.
    |
    */

    'meta' => [
        'version' => env('SOCIAL_META_GRAPH_VERSION', 'v18.0'),
        'base_url' => env('SOCIAL_META_GRAPH_BASE_URL', 'https://graph.facebook.com'),

        // Meta fetches the image itself from the URL we hand it, so our own
        // call returns as soon as the job is accepted; these are the ceilings
        // for OUR request, not for their crawl.
        'timeout' => (int) env('SOCIAL_META_TIMEOUT', 30),
        'connect_timeout' => (int) env('SOCIAL_META_CONNECT_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Instagram Container Readiness
    |--------------------------------------------------------------------------
    |
    | Publishing to Instagram is two calls with an asynchronous gap between
    | them: `/media` creates a container and Meta then downloads and transcodes
    | the image on their side, and `/media_publish` fails with a container that
    | is not FINISHED yet. Meta documents no callback for it, so the flow polls
    | the container's `status_code`.
    |
    | The poll is bounded on purpose. An image the crawler cannot reach never
    | leaves IN_PROGRESS, and an unbounded wait would pin a queue worker for as
    | long as that lasts. Exhausting the budget is reported as a failure with
    | the last status seen, which is the honest answer: the container exists but
    | was never publishable within the window.
    |
    */

    'instagram' => [
        'container_poll_attempts' => (int) env('SOCIAL_IG_POLL_ATTEMPTS', 10),
        'container_poll_seconds' => (int) env('SOCIAL_IG_POLL_SECONDS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Public Image Exposure
    |--------------------------------------------------------------------------
    |
    | Meta's crawler downloads the picture from a URL; it cannot be handed
    | bytes. The library's files are private in Drive and are served by the POS
    | itself, so a publication mints a controlled share link (the same
    | mechanism as `media_share_links`) and gives Meta that URL.
    |
    | THE WINDOW IS THE SMALLEST ONE THE CATALOG OFFERS. Meta fetches the image
    | within seconds of the call and re-hosts it on their CDN; the URL is not
    | needed afterwards, and every extra hour it stays alive is an hour in which
    | a link pasted into an error report keeps working. The value must be one of
    | `media.share_links.expiration_options` — the closed list is a security
    | control and this module does not get to widen it.
    |
    */

    'image_link' => [
        'expires_in_hours' => (int) env('SOCIAL_IMAGE_LINK_HOURS', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caption Limits
    |--------------------------------------------------------------------------
    |
    | The provider's own ceilings, enforced before the call so a rejected
    | caption costs nothing and is reported next to the counter that produced
    | it. Instagram truncates at 2 200 characters; Facebook's limit is far
    | higher but a feed post that long is not a caption, it is an article.
    |
    */

    'captions' => [
        'facebook' => 5000,
        'instagram' => 2200,
        'whatsapp' => 700,
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp (Mock)
    |--------------------------------------------------------------------------
    |
    | The channel is wired end to end — catalog, toggle, job branch, log row —
    | but its publisher does not touch the network yet. Status publishing is not
    | offered by the Cloud API, so the real integration needs a decision that
    | has not been made (a broadcast list, a status via an unofficial bridge, or
    | a template message to an opted-in audience). Until then the mock records a
    | simulated outcome so the rest of the pipeline can be exercised.
    |
    | `enabled` false hides the toggle entirely; leaving it true keeps the
    | channel visible and honestly labelled as a simulation.
    |
    */

    'whatsapp' => [
        'enabled' => (bool) env('SOCIAL_WHATSAPP_ENABLED', true),
        'mock' => (bool) env('SOCIAL_WHATSAPP_MOCK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Publication Log
    |--------------------------------------------------------------------------
    |
    | `social_posts` rows are evidence: never updated after they close, never
    | deleted by the application. These values only drive the viewer's default
    | window so opening it does not scan the whole history.
    |
    */

    'log' => [
        'page_size' => 25,
        'per_file_limit' => 10,
    ],
];
