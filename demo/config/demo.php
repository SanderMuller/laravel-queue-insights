<?php

return [
    /*
     * Basic-auth credentials for the public demo. Read at config-cache
     * time so the values survive `php artisan config:cache` (Cloud's
     * deploy command). Reading env() directly inside the middleware
     * would return null after config caching and silently disable the
     * gate — see DemoBasicAuth.
     *
     * Both must be set to activate the gate; either blank disables it
     * (local dev default).
     */
    'basic_auth' => [
        'user' => env('DEMO_BASIC_USER', ''),
        'password' => env('DEMO_BASIC_PASS', ''),
    ],
];
