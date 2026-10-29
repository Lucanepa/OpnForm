<?php

/*
 * KSCW fork settings (see KSCW-FORK.md). Server-only: nothing here reaches the client.
 */
return [
    'wiedisync' => [
        // Directus of Wiedisync as the API container reaches it over the Docker network,
        // e.g. http://directus-kscw:8055. Unset = the "check in Wiedisync" option is dormant
        // (submissions are accepted unchecked, nothing is signed in).
        'url' => env('WIEDISYNC_API_URL'),
        // Plain service token; Wiedisync stores only its SHA-256 (OPNFORM_SERVICE_TOKEN_HASH).
        'service_token' => env('WIEDISYNC_SERVICE_TOKEN'),
        'timeout' => (int) env('WIEDISYNC_TIMEOUT', 5),
    ],
    'deepl' => [
        // "Translate with DeepL" in the form editor. A key ending in ":fx" = DeepL API Free.
        'api_key' => env('DEEPL_API_KEY'),
    ],
];
