<?php

/*
 * The ERP the Sylloo desktop apps talk to. Merged under the app's own `config/erp.php`: a key the app sets wins.
 */
return [
    'base_url' => env('ERP_BASE_URL', 'https://sylloo.app'),

    // Days a member may sign in by PIN offline after the device last confirmed their sign-in online, unless the ERP
    // sends its own number (`offline_login_days` in the device state and the sign-in answer).
    'offline_login_days' => (int) env('ERP_OFFLINE_LOGIN_DAYS', 7),

    // Domains images are downloaded from (each with its subdomains), besides the ERP's own host (ImageCache).
    'image_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('ERP_IMAGE_HOSTS', 'sylloo.app,sylloo.dev,sylloo.eu'))))),

    /*
     * The signed remote config (Rishadblack\SyllooAppBase\Config\RemoteConfig): off unless the app names its file.
     * Taken only when signed with the release key and every address in it is https on `allowed_domains`.
     */
    'remote_config' => [
        'enabled' => (bool) env('ERP_REMOTE_CONFIG_ENABLED', false),
        'url' => env('ERP_REMOTE_CONFIG_URL'),
        'public_key' => env('ERP_REMOTE_CONFIG_PUBLIC_KEY', 'tY3PIeex3kHMy10mvQZ+Zp86BKTcjt2+sm9lve/cG8g='),
        'allowed_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('ERP_REMOTE_CONFIG_DOMAINS', 'sylloo.app,sylloo.dev,sylloo.eu'))))),
    ],
];
