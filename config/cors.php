<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    /*
     * Model previews are sandboxed, so the browser treats them as an opaque
     * ("null") origin. Their fonts and Next.js RSC payloads are therefore
     * cross-origin requests. Only these read-only preview paths are opened;
     * no credentials are ever allowed.
     */
    'paths' => ['previews/*', 'recovered-previews/*'],

    'allowed_methods' => ['GET', 'HEAD'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
