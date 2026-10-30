<?php

use Illuminate\Support\Facades\Http;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;
use Joelseneque\HelpRequests\Services\GitHubIssueService;

beforeEach(function (): void {
    $this->settings = makeGitHubSettings(['github_default_labels' => ['help-request']]);

    $this->github = app(GitHubIssueService::class);
});

it('reports configured only when installed, enabled and pointed at a repository', function (): void {
    expect($this->github->isConfigured())->toBeTrue();

    $this->settings->update(['github_enabled' => false]);
    expect(app(GitHubIssueService::class)->isConfigured())->toBeFalse();

    $this->settings->update(['github_enabled' => true, 'github_repository' => null]);
    expect(app(GitHubIssueService::class)->isConfigured())->toBeFalse();

    $this->settings->update(['github_repository' => 'acme/app', 'github_installation_id' => null]);
    expect(app(GitHubIssueService::class)->isConfigured())->toBeFalse();
});

it('is not configured when the app credentials are missing from the environment', function (): void {
    config()->set('help-requests.github.private_key', null);

    expect(app(GitHubIssueService::class)->isConfigured())->toBeFalse();
});

it('authenticates api calls with the installation token', function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(['number' => 1, 'state' => 'open'], 201),
    ]);

    $this->github->createIssue(HelpRequest::factory()->create());

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghs_installationtoken'));
});

it('lists the repositories available to the installation', function (): void {
    Http::fake([
        'api.github.com/installation/repositories*' => Http::response([
            'repositories' => [
                ['full_name' => 'acme/website'],
                ['full_name' => 'acme/app'],
            ],
        ]),
    ]);

    expect($this->github->availableRepositories())->toBe([
        'acme/app' => 'acme/app',
        'acme/website' => 'acme/website',
    ]);
});

it('does not cache an empty repository list after a failure', function (): void {
    Http::fakeSequence('api.github.com/installation/repositories*')
        ->push(['message' => 'Bad gateway'], 502)
        ->push(['repositories' => [['full_name' => 'acme/app']]], 200);

    expect($this->github->availableRepositories())->toBe([]);

    // A cached empty list would make this second call never reach GitHub.
    expect($this->github->availableRepositories())->toHaveCount(1);
});

it('serves the repository list from cache on the second call', function (): void {
    Http::fake([
        'api.github.com/installation/repositories*' => Http::response([
            'repositories' => [['full_name' => 'acme/app']],
        ]),
    ]);

    $this->github->availableRepositories();
    $this->github->availableRepositories();

    Http::assertSentCount(1);
});

it('returns no repositories when the app is not installed', function (): void {
    Http::fake();

    $this->settings->update(['github_installation_id' => null]);

    expect(app(GitHubIssueService::class)->availableRepositories())->toBe([]);
    Http::assertNothingSent();
});

it('normalises a pasted github url into owner/repository', function (string $input): void {
    expect(HelpRequestSetting::normaliseGithubRepository($input))->toBe('acme/app');
})->with([
    'acme/app',
    'https://github.com/acme/app',
    'https://github.com/acme/app.git',
    ' github.com/acme/app/ ',
]);

it('creates a github issue and links it to the help request', function (): void {
    Http::fake([
        'api.github.com/repos/acme/app/issues' => Http::response([
            'number' => 42,
            'state' => 'open',
            'html_url' => 'https://github.com/acme/app/issues/42',
        ], 201),
    ]);

    $helpRequest = HelpRequest::factory()->create(['comment' => 'The quote PDF will not download']);

    $result = $this->github->createIssue($helpRequest);

    expect($result['ok'])->toBeTrue();
    expect($helpRequest->refresh()->github_issue_number)->toBe(42);
    expect($helpRequest->github_repository)->toBe('acme/app');
    expect($helpRequest->github_issue_url)->toBe('https://github.com/acme/app/issues/42');
    expect($helpRequest->github_issue_state)->toBe('open');

    Http::assertSent(function ($request) use ($helpRequest): bool {
        return $request['title'] === "Help request #{$helpRequest->id}: The quote PDF will not download"
            && $request['labels'] === ['help-request']
            && str_contains($request['body'], 'The quote PDF will not download');
    });
});

it('does not create a second issue for an already linked request', function (): void {
    Http::fake();

    $helpRequest = HelpRequest::factory()->create(['github_issue_number' => 7]);

    $result = $this->github->createIssue($helpRequest);

    expect($result['ok'])->toBeFalse();
    Http::assertNothingSent();
});

it('surfaces a github error when issue creation is rejected', function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(['message' => 'Validation Failed'], 422),
    ]);

    $helpRequest = HelpRequest::factory()->create();

    $result = $this->github->createIssue($helpRequest);

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('422')->toContain('Validation Failed');
    expect($helpRequest->refresh()->github_issue_number)->toBeNull();
});

it('maps issue state to a help request status', function (array $issue, HelpRequestStatus $expected): void {
    expect($this->github->statusForIssue($issue))->toBe($expected);
})->with([
    'open issue' => [['state' => 'open'], HelpRequestStatus::Open],
    'closed as completed' => [['state' => 'closed', 'state_reason' => 'completed'], HelpRequestStatus::Resolved],
    'closed with no reason' => [['state' => 'closed'], HelpRequestStatus::Resolved],
    'closed as not planned' => [['state' => 'closed', 'state_reason' => 'not_planned'], HelpRequestStatus::Closed],
]);

it('keeps an in progress status while the issue stays open', function (): void {
    expect($this->github->statusForIssue(['state' => 'open'], HelpRequestStatus::InProgress))
        ->toBe(HelpRequestStatus::InProgress);
});

it('resolves the help request when the linked issue is closed on github', function (): void {
    $helpRequest = HelpRequest::factory()->create([
        'github_issue_number' => 42,
        'github_repository' => 'acme/app',
        'github_issue_state' => 'open',
        'status' => HelpRequestStatus::Open,
    ]);

    Http::fake([
        'api.github.com/repos/acme/app/issues/42' => Http::response([
            'number' => 42,
            'state' => 'closed',
            'state_reason' => 'completed',
            'html_url' => 'https://github.com/acme/app/issues/42',
        ]),
    ]);

    $result = $this->github->syncFromGithub($helpRequest);

    expect($result['ok'])->toBeTrue();
    expect($helpRequest->refresh()->status)->toBe(HelpRequestStatus::Resolved);
    expect($helpRequest->github_issue_state)->toBe('closed');
    expect($helpRequest->resolved_at)->not->toBeNull();
    expect($helpRequest->github_synced_at)->not->toBeNull();
});

it('reopening the issue on github clears the resolved timestamp', function (): void {
    $helpRequest = HelpRequest::factory()->create([
        'github_issue_number' => 42,
        'github_repository' => 'acme/app',
        'github_issue_state' => 'closed',
        'status' => HelpRequestStatus::Resolved,
        'resolved_at' => now(),
    ]);

    Http::fake([
        'api.github.com/*' => Http::response(['number' => 42, 'state' => 'open']),
    ]);

    $this->github->syncFromGithub($helpRequest);

    expect($helpRequest->refresh()->status)->toBe(HelpRequestStatus::Open);
    expect($helpRequest->resolved_at)->toBeNull();
});

it('closes the github issue when the help request is resolved locally', function (): void {
    $helpRequest = HelpRequest::factory()->create([
        'github_issue_number' => 42,
        'github_repository' => 'acme/app',
        'github_issue_state' => 'open',
        'status' => HelpRequestStatus::Resolved,
    ]);

    Http::fake([
        'api.github.com/*' => Http::response(['number' => 42, 'state' => 'closed']),
    ]);

    $result = $this->github->pushStatus($helpRequest);

    expect($result['ok'])->toBeTrue();
    expect($helpRequest->refresh()->github_issue_state)->toBe('closed');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && $request['state'] === 'closed'
        && $request['state_reason'] === 'completed');
});

it('closes the github issue as not planned when the request is closed', function (): void {
    $helpRequest = HelpRequest::factory()->create([
        'github_issue_number' => 42,
        'github_repository' => 'acme/app',
        'github_issue_state' => 'open',
        'status' => HelpRequestStatus::Closed,
    ]);

    Http::fake(['api.github.com/*' => Http::response(['number' => 42, 'state' => 'closed'])]);

    $this->github->pushStatus($helpRequest);

    Http::assertSent(fn ($request) => $request['state_reason'] === 'not_planned');
});

it('skips pushing when github already matches the local status', function (): void {
    Http::fake();

    $helpRequest = HelpRequest::factory()->create([
        'github_issue_number' => 42,
        'github_repository' => 'acme/app',
        'github_issue_state' => 'closed',
        'status' => HelpRequestStatus::Resolved,
    ]);

    expect($this->github->pushStatus($helpRequest)['ok'])->toBeTrue();
    Http::assertNothingSent();
});

it('reports a helpful message when the installation token is rejected', function (): void {
    Http::fake(['api.github.com/repos/*' => Http::response(['message' => 'Bad credentials'], 401)]);

    $result = $this->github->testConnection();

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('rejected the installation token');
});

it('reports a repository the installation cannot reach', function (): void {
    Http::fake(['api.github.com/repos/*' => Http::response(['message' => 'Not Found'], 404)]);

    $result = $this->github->testConnection();

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('included in the app installation');
});

it('reports a repository with issues disabled', function (): void {
    Http::fake([
        'api.github.com/repos/*' => Http::response([
            'full_name' => 'acme/app',
            'has_issues' => false,
        ]),
    ]);

    $result = $this->github->testConnection();

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('Issues are disabled');
});

it('reports an installation without write access to issues', function (): void {
    Http::fake([
        'api.github.com/repos/*' => Http::response(['full_name' => 'acme/app', 'has_issues' => true]),
        'api.github.com/app/installations/555' => Http::response(['permissions' => ['issues' => 'read']]),
    ]);

    $result = $this->github->testConnection();

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('does not have write access to Issues');
});

it('reports a successful connection test', function (): void {
    Http::fake([
        'api.github.com/repos/*' => Http::response(['full_name' => 'acme/app', 'has_issues' => true]),
        'api.github.com/app/installations/555' => Http::response(['permissions' => ['issues' => 'write']]),
    ]);

    expect($this->github->testConnection())->toMatchArray(['ok' => true]);
});

it('tells the user to install the app before testing', function (): void {
    Http::fake();

    $this->settings->update(['github_installation_id' => null]);

    $result = app(GitHubIssueService::class)->testConnection();

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('not installed yet');
    Http::assertNothingSent();
});

it('tells the user to pick a repository once installed', function (): void {
    Http::fake();

    $this->settings->update(['github_repository' => null]);

    $result = app(GitHubIssueService::class)->testConnection();

    expect($result['ok'])->toBeFalse();
    expect($result['message'])->toContain('no repository has been selected');
});
