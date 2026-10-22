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
    | Parley — the help chat
    |--------------------------------------------------------------------------
    |
    | The widget's chat tab. It answers from the app's own help library: the
    | markdown files in `knowledge_paths` (files starting with an underscore
    | are the primer, read on every question). Questions it cannot answer are
    | filed as internal "Help article" requests for somebody to write up.
    |
    | Without an API key the tab is not shown. `effort` trades thoroughness for
    | speed and cost; `low` suits a help chat. `inline_kb` is how much of the
    | library goes into every prompt; articles past it are listed by title and
    | read on demand. `fallbacks` re-runs a question the model's safety checks
    | decline on the model Anthropic recommends for that case.
    |
    */

    'parley' => [
        'enabled' => env('HELP_REQUESTS_PARLEY', true),
        'name' => 'Parley',
        'knowledge_paths' => [resource_path('help')],

        'api_key' => env('PARLEY_ANTHROPIC_KEY', env('ANTHROPIC_API_KEY')),
        'endpoint' => env('PARLEY_ANTHROPIC_ENDPOINT', 'https://api.anthropic.com/v1'),
        'version' => '2023-06-01',
        'model' => env('PARLEY_MODEL', 'claude-opus-5-5'),
        'effort' => env('PARLEY_EFFORT', 'low'),
        'max_tokens' => 8000,
        'timeout' => 90,
        'fallbacks' => true,
        'inline_kb' => 150,

        // Questions per conversation before Parley asks for a fresh chat, and
        // per person per hour.
        'max_questions' => 20,
        'questions_per_hour' => 40,

        // Each day starts a new chat; earlier ones are listed under Chats.
        // The day is on this clock (null is the app's timezone), which
        // HelpRequestsPlugin::parleyTimezone() can override per panel.
        'timezone' => env('PARLEY_TIMEZONE'),

        // A chat longer than this many messages is folded above its last few.
        'condense_after' => 6,

        // Also notify admins (as for a new request) when a gap is filed.
        'notify_admins_of_gaps' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachment storage
    |--------------------------------------------------------------------------
    |
    | The disk must serve public URLs: GitHub issues and emails link the files
    | directly. Keep HTML, SVG and scripts out of accepted_file_types for that
    | reason — served from your domain they would run as your site.
    |
    */

    'storage' => [
        'disk' => 'public',
        'directory' => 'help-requests',
        'max_size_kb' => 5120,
        // Attachments (screenshots and files) per request or reply.
        'max_files' => 5,
        'accepted_file_types' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/csv',
            'text/plain',
        ],
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
