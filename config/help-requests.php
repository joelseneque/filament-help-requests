<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Users
    |--------------------------------------------------------------------------
    |
    | The authenticatable model that raises and answers help requests, and the
    | columns searched when an admin filters the list by requester.
    |
    */

    'user_model' => env('HELP_REQUESTS_USER_MODEL', 'App\\Models\\User'),

    'user_searchable_columns' => ['name', 'email'],

    /*
    |--------------------------------------------------------------------------
    | Admin recipient
    |--------------------------------------------------------------------------
    |
    | Address emailed when a user submits a request or replies to one. When it
    | belongs to a user, that user also gets the in-app notification; otherwise
    | every user holding `admin_role` does (requires spatie/laravel-permission).
    | Leave null to skip the email and rely on in-app notifications alone.
    |
    */

    'recipient' => env('HELP_REQUEST_EMAIL'),

    'admin_role' => 'super_admin',

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Channels used when telling a requester about a reply or resolution. Swap
    | the delivery entirely with HelpRequestsPlugin::sendNotificationsUsing().
    |
    */

    'notifications' => [
        'channels' => ['database', 'mail'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Video links
    |--------------------------------------------------------------------------
    |
    | Lets users paste a screen recording link with their request. Hosts are
    | matched with their subdomains; an empty list accepts any https URL.
    | Loom share links are embedded as a player on the admin request page.
    |
    | "Record a Loom" first tries `record_app_url` (the Loom desktop app's URL
    | scheme) and falls back to `record_url` in a new tab when no app answers.
    | Set `record_app_url` to null to always go straight to the website.
    |
    | Turn the field off per panel with HelpRequestsPlugin::make()->videoLinks(false).
    |
    */

    'video' => [
        'enabled' => true,
        'record_url' => 'https://www.loom.com/',
        'record_app_url' => 'loomDesktop://',
        'allowed_hosts' => ['loom.com'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Screenshot storage
    |--------------------------------------------------------------------------
    */

    'storage' => [
        'disk' => 'public',
        'directory' => 'help-requests',
        'max_size_kb' => 5120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | Shown in emails and in the text posted to GitHub issues.
    |
    */

    'branding' => [
        'name' => env('HELP_REQUESTS_APP_NAME', env('APP_NAME', 'Laravel')),
        'logo_url' => env('HELP_REQUESTS_LOGO_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The webhook is registered without the `web` group so it needs no CSRF
    | exemption. The install routes need a session and a signed-in user.
    |
    */

    'routes' => [
        'webhook_path' => 'webhooks/github',
        'github_connect_path' => 'github/connect',
        'github_callback_path' => 'github/callback',
        'middleware' => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub App
    |--------------------------------------------------------------------------
    |
    | Optional. Credentials for a GitHub App with Issues read & write. The
    | private key can be raw PEM, base64 of the PEM, or a path to a .pem file.
    | Which repository to use is chosen per database on the settings page.
    |
    */

    'github' => [
        'api_url' => env('GITHUB_API_URL', 'https://api.github.com'),
        'app_id' => env('GITHUB_APP_ID'),
        'app_slug' => env('GITHUB_APP_SLUG'),
        'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
        'webhook_secret' => env('GITHUB_APP_WEBHOOK_SECRET'),

        // Hourly poll of linked issues, in case a webhook delivery is missed.
        'schedule_sync' => true,
    ],

];
