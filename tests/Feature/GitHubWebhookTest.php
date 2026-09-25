<?php

use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\Models\HelpRequest;

beforeEach(function (): void {
    $this->settings = makeGitHubSettings();

    $this->helpRequest = HelpRequest::factory()->create([
        'status' => HelpRequestStatus::Open,
        'github_issue_number' => 42,
        'github_repository' => 'acme/app',
        'github_issue_state' => 'open',
    ]);
});

it('marks the help request resolved when the issue is closed', function (): void {
    postGithubWebhook(githubPayload('closed', ['state_reason' => 'completed']))
        ->assertSuccessful()
        ->assertJson(['success' => true, 'status' => 'resolved']);

    expect($this->helpRequest->refresh()->status)->toBe(HelpRequestStatus::Resolved);
    expect($this->helpRequest->github_issue_state)->toBe('closed');
    expect($this->helpRequest->resolved_at)->not->toBeNull();
});

it('marks the help request closed when the issue is closed as not planned', function (): void {
    postGithubWebhook(githubPayload('closed', ['state_reason' => 'not_planned']))
        ->assertSuccessful();

    expect($this->helpRequest->refresh()->status)->toBe(HelpRequestStatus::Closed);
    expect($this->helpRequest->resolved_at)->toBeNull();
});

it('reopens the help request when the issue is reopened', function (): void {
    $this->helpRequest->update([
        'status' => HelpRequestStatus::Resolved,
        'resolved_at' => now(),
        'github_issue_state' => 'closed',
    ]);

    postGithubWebhook(githubPayload('reopened'))->assertSuccessful();

    expect($this->helpRequest->refresh()->status)->toBe(HelpRequestStatus::Open);
    expect($this->helpRequest->github_issue_state)->toBe('open');
});

it('unlinks the help request when the issue is deleted', function (): void {
    postGithubWebhook(githubPayload('deleted'))
        ->assertSuccessful()
        ->assertJson(['status' => 'unlinked']);

    expect($this->helpRequest->refresh()->github_issue_number)->toBeNull();
    expect($this->helpRequest->github_issue_url)->toBeNull();
});

it('rejects a payload with an invalid signature', function (): void {
    postGithubWebhook(githubPayload('closed'), 'wrong-secret')
        ->assertUnauthorized();

    expect($this->helpRequest->refresh()->status)->toBe(HelpRequestStatus::Open);
});

it('rejects a payload with no signature when a secret is configured', function (): void {
    postGithubWebhook(githubPayload('closed'), null)->assertUnauthorized();
});

it('answers a ping event', function (): void {
    postGithubWebhook(['zen' => 'Design for failure.'], event: 'ping')
        ->assertSuccessful()
        ->assertJson(['status' => 'pong']);
});

it('ignores events other than issues', function (): void {
    postGithubWebhook(githubPayload('closed'), event: 'push')
        ->assertSuccessful()
        ->assertJson(['status' => 'ignored']);

    expect($this->helpRequest->refresh()->status)->toBe(HelpRequestStatus::Open);
});

it('ignores issue actions that do not change status', function (): void {
    postGithubWebhook(githubPayload('labeled'))
        ->assertSuccessful()
        ->assertJson(['status' => 'ignored']);
});

it('ignores an issue from a different repository', function (): void {
    postGithubWebhook(githubPayload('closed', repository: 'someone-else/repo'))
        ->assertSuccessful()
        ->assertJson(['status' => 'no_match']);

    expect($this->helpRequest->refresh()->status)->toBe(HelpRequestStatus::Open);
});

it('ignores an issue number that matches no help request', function (): void {
    postGithubWebhook(githubPayload('closed', ['number' => 999]))
        ->assertSuccessful()
        ->assertJson(['status' => 'no_match']);
});

it('flags a reconnect when the app installation is deleted', function (): void {
    postGithubWebhook(['action' => 'deleted', 'installation' => ['id' => 555]], event: 'installation')
        ->assertSuccessful()
        ->assertJson(['status' => 'disconnected']);

    $settings = $this->settings->refresh();

    expect($settings->githubNeedsReconnect())->toBeTrue();
    expect($settings->github_installation_token)->toBeNull();
});

it('flags a reconnect when the app installation is suspended', function (): void {
    postGithubWebhook(['action' => 'suspend', 'installation' => ['id' => 555]], event: 'installation')
        ->assertSuccessful();

    expect($this->settings->refresh()->githubNeedsReconnect())->toBeTrue();
});

it('clears the reconnect flag when the installation is unsuspended', function (): void {
    $this->settings->update(['github_installation_failed_at' => now()]);

    postGithubWebhook(['action' => 'unsuspend', 'installation' => ['id' => 555]], event: 'installation')
        ->assertSuccessful()
        ->assertJson(['status' => 'restored']);

    expect($this->settings->refresh()->githubNeedsReconnect())->toBeFalse();
});

it('ignores installation events for a different installation', function (): void {
    postGithubWebhook(['action' => 'deleted', 'installation' => ['id' => 999]], event: 'installation')
        ->assertSuccessful()
        ->assertJson(['status' => 'no_match']);

    expect($this->settings->refresh()->githubNeedsReconnect())->toBeFalse();
});

it('rejects an installation event with a bad signature', function (): void {
    postGithubWebhook(['action' => 'deleted', 'installation' => ['id' => 555]], 'wrong-secret', 'installation')
        ->assertUnauthorized();

    expect($this->settings->refresh()->githubNeedsReconnect())->toBeFalse();
});
