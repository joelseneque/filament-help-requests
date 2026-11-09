<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Joelseneque\HelpRequests\Enums\HelpRequestPriority;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;
use Joelseneque\HelpRequests\Services\GitHubIssueService;

beforeEach(function (): void {
    $this->settings = makeGitHubSettings(['github_default_labels' => ['help request']]);

    // The org's issue-fields lookup answers with $this->issueFields: none by
    // default, and a test that needs fields swaps the response in. Returning
    // null lets every other request fall through to the test's own fakes.
    $this->issueFields = Http::response(['message' => 'Not Found'], 404);
    Http::fake(fn ($request) => str_contains($request->url(), '/issue-fields') ? $this->issueFields : null);

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
        return $request->method() === 'POST'
            && $request['title'] === "Help request #{$helpRequest->id}: The quote PDF will not download"
            && $request['labels'] === ['help request']
            && str_contains($request['body'], 'The quote PDF will not download');
    });
});

it('creates the issue with the labels the label hook adds', function (): void {
    Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['number' => 42, 'state' => 'open'], 201)]);

    HelpRequests::labelsUsing(fn (HelpRequest $request, array $defaults): array => [...$defaults, 'triaged']);

    $this->github->createIssue(HelpRequest::factory()->create());

    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['labels'] === ['help request', 'triaged']);
});

it('passes the request and the default labels to the label hook', function (): void {
    Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['number' => 42, 'state' => 'open'], 201)]);

    $received = null;

    HelpRequests::labelsUsing(function (HelpRequest $request, array $defaults) use (&$received): array {
        $received = [$request->id, $defaults];

        return $defaults;
    });

    $helpRequest = HelpRequest::factory()->create();

    $this->github->createIssue($helpRequest);

    expect($received)->toBe([$helpRequest->id, ['help request']]);
});

it('trims the hook labels and drops blanks and repeats', function (): void {
    Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['number' => 42, 'state' => 'open'], 201)]);

    HelpRequests::labelsUsing(fn (): array => [' bug ', '', 'bug', '  ', 'help request']);

    $this->github->createIssue(HelpRequest::factory()->create());

    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['labels'] === ['bug', 'help request']);
});

it('sends no labels when the label hook returns none', function (): void {
    Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['number' => 42, 'state' => 'open'], 201)]);

    HelpRequests::labelsUsing(fn (): array => []);

    $this->github->createIssue(HelpRequest::factory()->create());

    Http::assertSent(fn ($request): bool => ! array_key_exists('labels', $request->data()));
});

it('still creates the issue with the default labels when the label hook throws', function (): void {
    Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['number' => 42, 'state' => 'open'], 201)]);
    Log::spy();

    HelpRequests::labelsUsing(fn (): array => throw new RuntimeException('Triage service is down'));

    $helpRequest = HelpRequest::factory()->create();

    $result = $this->github->createIssue($helpRequest);

    expect($result['ok'])->toBeTrue();
    expect($helpRequest->refresh()->github_issue_number)->toBe(42);
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['labels'] === ['help request']);
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context['help_request_id'] === $helpRequest->id
            && $context['error'] === 'Triage service is down',
    );
});

it('retries with the default labels when github rejects the hook labels', function (): void {
    Http::fakeSequence('api.github.com/repos/acme/app/issues')
        ->push(['message' => 'Validation Failed'], 422)
        ->push(['number' => 42, 'state' => 'open'], 201);
    Log::spy();

    HelpRequests::labelsUsing(fn (HelpRequest $request, array $defaults): array => [...$defaults, 'missing-label']);

    $helpRequest = HelpRequest::factory()->create();

    $result = $this->github->createIssue($helpRequest);

    expect($result['ok'])->toBeTrue();
    expect($helpRequest->refresh()->github_issue_number)->toBe(42);
    expect(issueCreates()->map(fn ($request): array => $request['labels'])->all())
        ->toBe([['help request', 'missing-label'], ['help request']]);
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context['help_request_id'] === $helpRequest->id
            && $context['labels'] === ['help request', 'missing-label'],
    );
});

it('adds the priority and due date to the issue body', function (): void {
    Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['number' => 42, 'state' => 'open'], 201)]);
    $helpRequest = HelpRequest::factory()->create(['priority' => HelpRequestPriority::High]);

    $this->github->createIssue($helpRequest);

    $line = '**Priority:** High · due '.$helpRequest->due_at->format('d M Y').', 11:59 PM';
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && str_contains($request['body'], $line));
});

it('leaves the priority and fields off issues for requests without a due date', function (): void {
    Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['number' => 42, 'state' => 'open'], 201)]);

    $this->github->createIssue(HelpRequest::factory()->create(['is_internal' => true]));

    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['labels'] === ['help request']
        && ! array_key_exists('issue_field_values', $request->data())
        && ! str_contains($request['body'], '**Priority:**'));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/issue-fields'));
});

it('sets the priority and target date fields on the new issue', function (): void {
    Http::fake([
        'api.github.com/repos/acme/app/issues' => Http::response(['number' => 42, 'state' => 'open'], 201),
    ]);
    $this->issueFields = Http::response(fakeIssueFields());

    $helpRequest = HelpRequest::factory()->create(['priority' => HelpRequestPriority::High]);

    expect($this->github->createIssue($helpRequest)['ok'])->toBeTrue();
    expect(issueCreates()->sole()['issue_field_values'])->toBe([
        ['field_id' => 11, 'value' => 'High'],
        ['field_id' => 12, 'value' => $helpRequest->due_at->toDateString()],
    ]);
});

it('creates the issue without field values when the organisation has none', function (): void {
    Http::fake([
        'api.github.com/repos/acme/app/issues' => Http::response(['number' => 42, 'state' => 'open'], 201),
    ]);

    expect($this->github->createIssue(HelpRequest::factory()->create())['ok'])->toBeTrue();
    expect(issueCreates()->sole()->data())->not->toHaveKey('issue_field_values');
});

it('creates the issue without field values when the app cannot read the fields', function (): void {
    Http::fake([
        'api.github.com/repos/acme/app/issues' => Http::response(['number' => 42, 'state' => 'open'], 201),
    ]);
    $this->issueFields = Http::response(['message' => 'Resource not accessible by integration'], 403);
    Log::spy();

    expect($this->github->createIssue(HelpRequest::factory()->create())['ok'])->toBeTrue();
    expect(issueCreates()->sole()->data())->not->toHaveKey('issue_field_values');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'issue fields'));
});

it('skips the priority field when it has no matching option', function (): void {
    Http::fake([
        'api.github.com/repos/acme/app/issues' => Http::response(['number' => 42, 'state' => 'open'], 201),
    ]);
    $this->issueFields = Http::response(fakeIssueFields(['P1', 'P2']));

    $helpRequest = HelpRequest::factory()->create();
    $this->github->createIssue($helpRequest);

    expect(issueCreates()->sole()['issue_field_values'])->toBe([
        ['field_id' => 12, 'value' => $helpRequest->due_at->toDateString()],
    ]);
});

it('looks the fields up once per organisation', function (): void {
    Http::fake([
        'api.github.com/repos/acme/app/issues' => Http::response(['number' => 42, 'state' => 'open'], 201),
    ]);
    $this->issueFields = Http::response(fakeIssueFields());

    $this->github->createIssue(HelpRequest::factory()->create());
    $this->github->createIssue(HelpRequest::factory()->create());

    expect(Http::recorded()->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/issue-fields')))->toHaveCount(1)
        ->and(issueCreates())->toHaveCount(2);
});

it('retries without field values when github rejects the issue', function (): void {
    $this->issueFields = Http::response(fakeIssueFields());
    Http::fakeSequence('api.github.com/repos/acme/app/issues')
        ->push(['message' => 'Validation Failed'], 422)
        ->push(['number' => 42, 'state' => 'open'], 201);

    $helpRequest = HelpRequest::factory()->create();

    expect($this->github->createIssue($helpRequest)['ok'])->toBeTrue()
        ->and($helpRequest->refresh()->github_issue_number)->toBe(42);
    [$first, $second] = issueCreates()->all();
    expect($first->data())->toHaveKey('issue_field_values')
        ->and($second->data())->not->toHaveKey('issue_field_values')
        ->and($second['labels'])->toBe(['help request']);
});

it('forgets the label hook on flush', function (): void {
    HelpRequests::labelsUsing(fn (): array => ['triaged']);

    HelpRequests::flush();

    expect(HelpRequests::issueLabels(HelpRequest::factory()->create(), ['help request']))->toBe(['help request']);
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
    // Nothing changed from the defaults, so there is nothing to fall back to.
    expect(issueCreates())->toHaveCount(1);
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

it('says when the connection test finds the priority fields', function (): void {
    Http::fake([
        'api.github.com/repos/*' => Http::response(['full_name' => 'acme/app', 'has_issues' => true]),
        'api.github.com/app/installations/555' => Http::response(['permissions' => ['issues' => 'write']]),
    ]);
    $this->issueFields = Http::response(fakeIssueFields());

    $result = $this->github->testConnection();

    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toContain('Priority and Target date fields: found.');
});

it('says when the connection test cannot find the priority fields', function (): void {
    Http::fake([
        'api.github.com/repos/*' => Http::response(['full_name' => 'acme/app', 'has_issues' => true]),
        'api.github.com/app/installations/555' => Http::response(['permissions' => ['issues' => 'write']]),
    ]);

    $result = $this->github->testConnection();

    expect($result['ok'])->toBeTrue()
        ->and($result['message'])->toContain('Priority and Target date fields: not available');
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

it('comments on the issue and updates its fields when the priority changes', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1])]);
    $this->issueFields = Http::response(fakeIssueFields());

    $helpRequest = HelpRequest::factory()->linkedToIssue()->create(['priority' => HelpRequestPriority::Low]);
    $helpRequest->update(['due_at' => '2026-12-09 10:00']);

    $result = $this->github->pushPriority($helpRequest, HelpRequestPriority::Medium);

    expect($result['ok'])->toBeTrue();
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/repos/acme/app/issues/42/comments')
        && $request['body'] === 'Priority changed from Medium to Low in '.HelpRequests::appName().' · due 09 Dec 2026, 10:00 AM');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/repos/acme/app/issues/42/issue-field-values')
        && $request['issue_field_values'] === [['field_id' => 11, 'value' => 'Low'], ['field_id' => 12, 'value' => '2026-12-09']]);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/labels'));
});

it('only comments when the organisation has no issue fields', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1])]);

    $helpRequest = HelpRequest::factory()->linkedToIssue()->create(['priority' => HelpRequestPriority::High]);

    expect($this->github->pushPriority($helpRequest, HelpRequestPriority::Medium)['ok'])->toBeTrue();
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/issues/42/comments'));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/issue-field-values'));
});

it('keeps the comment when the field update fails', function (): void {
    Http::fake(fn ($request) => str_contains($request->url(), '/issue-field-values')
        ? Http::response(['message' => 'Resource not accessible by integration'], 403)
        : Http::response(['id' => 1]));
    $this->issueFields = Http::response(fakeIssueFields());
    Log::spy();

    $helpRequest = HelpRequest::factory()->linkedToIssue()->create(['priority' => HelpRequestPriority::High]);

    expect($this->github->pushPriority($helpRequest, HelpRequestPriority::Medium)['ok'])->toBeTrue();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'GitHub issue field update failed');
});

it('keeps the comment when the field update cannot reach github', function (): void {
    Http::fake(fn ($request) => str_contains($request->url(), '/issue-field-values')
        ? throw new ConnectionException('Timed out')
        : Http::response(['id' => 1]));
    $this->issueFields = Http::response(fakeIssueFields());
    Log::spy();

    $helpRequest = HelpRequest::factory()->linkedToIssue()->create(['priority' => HelpRequestPriority::High]);

    expect($this->github->pushPriority($helpRequest, HelpRequestPriority::Medium)['ok'])->toBeTrue()
        ->and($helpRequest->refresh()->github_synced_at)->not->toBeNull();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => $message === 'GitHub issue field update failed');
});

it('reports when github rejects the priority comment', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'Server Error'], 500)]);

    $helpRequest = HelpRequest::factory()->linkedToIssue()->create(['priority' => HelpRequestPriority::High]);

    $result = $this->github->pushPriority($helpRequest, HelpRequestPriority::Medium);

    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toStartWith('GitHub returned 500');
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/issue-field-values'));
});

it('does nothing on github when the priority is unchanged or there is no issue', function (): void {
    Http::fake();

    $linked = HelpRequest::factory()->linkedToIssue()->create();
    $unlinked = HelpRequest::factory()->create(['priority' => HelpRequestPriority::High]);

    $this->github->pushPriority($linked, HelpRequestPriority::Medium);
    $this->github->pushPriority($unlinked, HelpRequestPriority::Medium);

    Http::assertNothingSent();
});

/**
 * The create-issue requests sent, leaving out the issue-fields lookup.
 *
 * @return Collection<int, Request>
 */
function issueCreates(): Collection
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn ($request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/repos/acme/app/issues'))
        ->values();
}
