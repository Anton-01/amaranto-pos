<?php

/*
|--------------------------------------------------------------------------
| Modulo de Medios Enterprise
|--------------------------------------------------------------------------
|
| Policy of the centralized media library. Everything that governs BEHAVIOUR
| lives here; everything that governs WHAT IS ALLOWED lives in the database
| (`allowed_file_types`) and everything that governs WHO WE ARE against
| Google lives encrypted in `drive_credentials`.
|
| The split matters: an operator must be able to enable a new extension or
| rotate a service account without a redeploy, but must NOT be able to widen
| the hard ceilings below from the browser.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Hard Upload Ceiling
    |--------------------------------------------------------------------------
    |
    | Absolute maximum accepted by the API, in kilobytes, regardless of what
    | `allowed_file_types.max_size_kb` says. A per-type limit can only be
    | STRICTER than this value, never looser: the ceiling protects PHP's
    | memory and the request timeout, which are properties of the server and
    | not of the file type.
    |
    */

    'max_upload_kb' => (int) env('MEDIA_MAX_UPLOAD_KB', 25600),

    /*
    |--------------------------------------------------------------------------
    | Google Drive Endpoints and OAuth Scope
    |--------------------------------------------------------------------------
    |
    | The module speaks the Drive v3 REST API directly over the framework's
    | HTTP client instead of pulling in google/apiclient. The surface used is
    | tiny (token, files, permissions) and a native implementation keeps the
    | credential path under our own control — which is the whole point of
    | storing the service account in the database.
    |
    | `drive.file` and NOT `drive`: the token may only touch files this
    | application created. A leaked token cannot enumerate the rest of the
    | organization's Drive.
    |
    */

    'drive' => [
        'token_endpoint' => 'https://oauth2.googleapis.com/token',
        'api_base' => 'https://www.googleapis.com/drive/v3',
        'upload_base' => 'https://www.googleapis.com/upload/drive/v3',
        'scope' => 'https://www.googleapis.com/auth/drive.file',

        // JWT assertion lifetime requested from Google, in seconds. Google
        // caps this at one hour.
        'token_ttl' => 3600,

        // Safety margin subtracted before caching the access token, so a
        // request never departs with a token that expires mid-flight.
        'token_leeway' => 300,

        // Bounded network time. A blocked egress port must fail in seconds
        // with a readable message, not hang until PHP's default timeout.
        'timeout' => (int) env('MEDIA_DRIVE_TIMEOUT', 20),
        'connect_timeout' => (int) env('MEDIA_DRIVE_CONNECT_TIMEOUT', 8),
        'upload_timeout' => (int) env('MEDIA_DRIVE_UPLOAD_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Share Links
    |--------------------------------------------------------------------------
    |
    | A share link is OUR token, never a Google "anyone with the link" grant.
    | The bytes are streamed by this application after it has validated the
    | token, so revoking a link is a database write and the file in Drive is
    | never reachable without passing through the POS.
    |
    */

    'share_links' => [
        // Raw token length in bytes before base64url encoding.
        'token_bytes' => 32,

        // Expiration windows offered by the UI, in hours. Closed list on
        // purpose: "never expires" is not an option a click should produce.
        'expiration_options' => [1, 6, 24, 72, 168, 720],

        'default_expiration_hours' => 24,

        // Upper bound for the optional download counter.
        'max_download_limit' => 1000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Retention
    |--------------------------------------------------------------------------
    |
    | Media audit rows are forensic evidence: they are never updated and never
    | deleted by the application. This value only drives the default window of
    | the admin viewer, so opening it does not scan years of history.
    |
    */

    'audit' => [
        'default_window_days' => 30,
        'page_size' => 25,
    ],
];
