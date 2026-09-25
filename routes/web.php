<?php

use Illuminate\Support\Facades\Route;
use Joelseneque\HelpRequests\Http\Controllers\GitHubAppController;
use Joelseneque\HelpRequests\Http\Controllers\GitHubWebhookController;

// Outside the `web` group on purpose: GitHub signs the payload, so it needs no
// session or CSRF token.
Route::post(config('help-requests.routes.webhook_path'), [GitHubWebhookController::class, 'handle'])
    ->name('help-requests.webhook');

// The GitHub App install flow (GitHub hosts the repository picker itself).
Route::middleware(config('help-requests.routes.middleware'))->group(function (): void {
    Route::get(config('help-requests.routes.github_connect_path'), [GitHubAppController::class, 'connect'])
        ->name('help-requests.github.connect');

    Route::get(config('help-requests.routes.github_callback_path'), [GitHubAppController::class, 'callback'])
        ->name('help-requests.github.callback');
});
