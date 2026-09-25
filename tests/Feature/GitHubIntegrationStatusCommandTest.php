<?php

use Illuminate\Support\Facades\Http;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;

it('passes when everything is configured', function (): void {
    makeGitHubSettings(['github_auto_create' => true]);

    Http::fake([
        'api.github.com/repos/*' => Http::response(['full_name' => 'acme/app', 'has_issues' => true]),
        'api.github.com/app/installations/555' => Http::response(['permissions' => ['issues' => 'write']]),
    ]);

    $this->artisan('help-requests:github-status')
        ->expectsOutputToContain('GITHUB_APP_ID')
        ->expectsOutputToContain('fully configured')
        ->assertSuccessful();
});

it('reports an unreadable private key', function (): void {
    makeGitHubSettings();

    config()->set('help-requests.github.private_key', 'storage/app/does-not-exist.pem');

    $this->artisan('help-requests:github-status')
        ->expectsOutputToContain('must exist on THIS server')
        ->assertFailed();
});

it('reports settings that were never connected on this database', function (): void {
    configureGitHubApp();
    HelpRequestSetting::create([]);

    $this->artisan('help-requests:github-status')
        ->expectsOutputToContain('connecting locally does not connect production')
        ->assertFailed();
});

it('reports auto-create being switched off', function (): void {
    makeGitHubSettings(['github_auto_create' => false]);

    Http::fake([
        'api.github.com/repos/*' => Http::response(['full_name' => 'acme/app', 'has_issues' => true]),
        'api.github.com/app/installations/555' => Http::response(['permissions' => ['issues' => 'write']]),
    ]);

    $this->artisan('help-requests:github-status')
        ->expectsOutputToContain('only be created by the button')
        ->assertFailed();
});

it('reports a dead installation', function (): void {
    makeGitHubSettings([
        'github_auto_create' => true,
        'github_installation_token_expires_at' => now()->subMinute(),
    ]);

    Http::fake(['api.github.com/app/installations/*' => Http::response(['message' => 'Not Found'], 404)]);

    $this->artisan('help-requests:github-status')->assertFailed();

    expect(HelpRequestSetting::first()->githubNeedsReconnect())->toBeTrue();
});

it('fails cleanly when no settings record exists', function (): void {
    configureGitHubApp();

    $this->artisan('help-requests:github-status')
        ->expectsOutputToContain('No help_request_settings row exists')
        ->assertFailed();
});
