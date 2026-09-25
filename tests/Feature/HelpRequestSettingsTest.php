<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\Filament\Pages\HelpRequestSettings;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Pages\ViewHelpRequest;
use Joelseneque\HelpRequests\Jobs\CreateGitHubIssueForHelpRequest;
use Joelseneque\HelpRequests\Livewire\HelpRequestWidget;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;
use Joelseneque\HelpRequests\Tests\Fixtures\User;
use Livewire\Livewire;

function superAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

it('saves the issue creation settings from the page', function (): void {
    makeGitHubSettings(['github_default_labels' => null, 'github_auto_create' => false]);

    Http::fake(['api.github.com/installation/repositories*' => Http::response([
        'repositories' => [['full_name' => 'acme/app']],
    ])]);

    Livewire::actingAs(superAdmin())
        ->test(HelpRequestSettings::class)
        ->fillForm([
            'github_repository' => 'acme/app',
            'github_enabled' => true,
            'github_auto_create' => true,
            'github_default_labels' => ['help-request', 'bug'],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = HelpRequestSetting::first();

    expect($settings->github_repository)->toBe('acme/app');
    expect($settings->github_auto_create)->toBeTrue();
    expect($settings->github_default_labels)->toBe(['help-request', 'bug']);
    expect($settings->isGithubConnected())->toBeTrue();
});

it('offers only the repositories the installation can reach', function (): void {
    makeGitHubSettings();

    Http::fake(['api.github.com/installation/repositories*' => Http::response([
        'repositories' => [
            ['full_name' => 'acme/app'],
            ['full_name' => 'acme/website'],
        ],
    ])]);

    Livewire::actingAs(superAdmin())
        ->test(HelpRequestSettings::class)
        ->assertFormFieldExists('github_repository', fn ($field): bool => $field->getOptions() === [
            'acme/app' => 'acme/app',
            'acme/website' => 'acme/website',
        ]);
});

it('hides the repository picker until the app is installed', function (): void {
    configureGitHubApp();
    HelpRequestSetting::create([]);

    Livewire::actingAs(superAdmin())
        ->test(HelpRequestSettings::class)
        ->assertFormFieldHidden('github_repository');
});

it('encrypts the stored installation token', function (): void {
    makeGitHubSettings();

    $raw = DB::table('help_request_settings')->first();

    expect($raw->github_installation_token)->not->toBe('ghs_installationtoken');
    expect(HelpRequestSetting::first()->github_installation_token)->toBe('ghs_installationtoken');
});

it('disconnects the installation from the page', function (): void {
    makeGitHubSettings();

    Http::fake(['api.github.com/installation/repositories*' => Http::response(['repositories' => []])]);

    Livewire::actingAs(superAdmin())
        ->test(HelpRequestSettings::class)
        ->call('disconnectGithub')
        ->assertNotified();

    $settings = HelpRequestSetting::first();

    expect($settings->isGithubInstalled())->toBeFalse();
    expect($settings->github_installation_token)->toBeNull();
    expect($settings->github_repository)->toBeNull();
    expect($settings->github_enabled)->toBeFalse();
});

it('blocks users without a settings role', function (): void {
    expect(HelpRequestSettings::canAccess())->toBeFalse();

    $this->actingAs(User::factory()->create(['is_admin' => false]));

    expect(HelpRequestSettings::canAccess())->toBeFalse();
});

it('reports a failed connection test on the page', function (): void {
    makeGitHubSettings();

    Http::fake([
        'api.github.com/repos/*' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => []]),
    ]);

    Livewire::actingAs(superAdmin())
        ->test(HelpRequestSettings::class)
        ->callAction('testGithubConnection')
        ->assertNotified();
});

it('queues an issue when auto create is on and a request is submitted', function (): void {
    Queue::fake();
    Mail::fake();

    makeGitHubSettings(['github_auto_create' => true]);

    Livewire::actingAs(User::factory()->create())
        ->test(HelpRequestWidget::class)
        ->set('comment', 'The dashboard will not load')
        ->call('submit')
        ->assertHasNoErrors();

    Queue::assertPushed(CreateGitHubIssueForHelpRequest::class);
});

it('does not queue an issue when auto create is off', function (): void {
    Queue::fake();
    Mail::fake();

    makeGitHubSettings(['github_auto_create' => false]);

    Livewire::actingAs(User::factory()->create())
        ->test(HelpRequestWidget::class)
        ->set('comment', 'The dashboard will not load')
        ->call('submit')
        ->assertHasNoErrors();

    Queue::assertNotPushed(CreateGitHubIssueForHelpRequest::class);
});

it('does not queue an issue when the app is not installed', function (): void {
    Queue::fake();
    Mail::fake();

    makeGitHubSettings(['github_auto_create' => true, 'github_installation_id' => null]);

    Livewire::actingAs(User::factory()->create())
        ->test(HelpRequestWidget::class)
        ->set('comment', 'The dashboard will not load')
        ->call('submit')
        ->assertHasNoErrors();

    Queue::assertNotPushed(CreateGitHubIssueForHelpRequest::class);
});

it('creates the issue from the help request page and closes it on resolve', function (): void {
    makeGitHubSettings();

    Http::fake([
        'api.github.com/repos/acme/app/issues' => Http::response([
            'number' => 42,
            'state' => 'open',
            'html_url' => 'https://github.com/acme/app/issues/42',
        ], 201),
        'api.github.com/repos/acme/app/issues/42' => Http::response([
            'number' => 42,
            'state' => 'closed',
        ]),
    ]);

    $helpRequest = HelpRequest::factory()->create(['status' => HelpRequestStatus::Open]);

    Livewire::actingAs(superAdmin())
        ->test(ViewHelpRequest::class, ['record' => $helpRequest->getKey()])
        ->callAction('createGithubIssue')
        ->assertNotified();

    expect($helpRequest->refresh()->github_issue_number)->toBe(42);

    Livewire::actingAs(superAdmin())
        ->test(ViewHelpRequest::class, ['record' => $helpRequest->getKey()])
        ->callAction(TestAction::make('updateStatus'), ['status' => HelpRequestStatus::Resolved->value]);

    expect($helpRequest->refresh()->status)->toBe(HelpRequestStatus::Resolved);
    expect($helpRequest->github_issue_state)->toBe('closed');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['state'] === 'closed');
});

it('syncs all linked issues with the sync command', function (): void {
    makeGitHubSettings();

    $helpRequest = HelpRequest::factory()->create([
        'status' => HelpRequestStatus::Open,
        'github_issue_number' => 42,
        'github_repository' => 'acme/app',
        'github_issue_state' => 'open',
    ]);

    Http::fake([
        'api.github.com/*' => Http::response(['number' => 42, 'state' => 'closed', 'state_reason' => 'completed']),
    ]);

    $this->artisan('help-requests:sync-github')->assertSuccessful();

    expect($helpRequest->refresh()->status)->toBe(HelpRequestStatus::Resolved);
    expect(HelpRequestSetting::first()->github_last_synced_at)->not->toBeNull();
});

it('does nothing when the sync command runs without github configured', function (): void {
    Http::fake();

    $this->artisan('help-requests:sync-github')->assertSuccessful();

    Http::assertNothingSent();
});
