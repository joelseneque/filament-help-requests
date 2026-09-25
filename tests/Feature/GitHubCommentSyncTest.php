<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\Mail\HelpRequestResolvedMail;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestReply;
use Joelseneque\HelpRequests\Notifications\HelpRequestResolvedNotification;
use Joelseneque\HelpRequests\Services\GitHubIssueService;
use Joelseneque\HelpRequests\Tests\Fixtures\User;

beforeEach(function (): void {
    $this->settings = makeGitHubSettings();

    $this->requester = User::factory()->create(['email' => 'requester@example.com']);

    $this->helpRequest = HelpRequest::factory()->create([
        'user_id' => $this->requester->id,
        'status' => HelpRequestStatus::Open,
        'github_issue_number' => 42,
        'github_repository' => 'acme/app',
        'github_issue_state' => 'open',
    ]);
});

function commentPayload(array $comment = [], string $action = 'created'): array
{
    return [
        'action' => $action,
        'issue' => ['number' => 42, 'state' => 'open'],
        'repository' => ['full_name' => 'acme/app'],
        'comment' => array_merge([
            'id' => 9001,
            'body' => 'Had a look — this was a caching issue.',
            'user' => ['login' => 'a-developer', 'type' => 'User'],
        ], $comment),
    ];
}

it('ignores issue comment webhooks entirely', function (string $action): void {
    Notification::fake();

    postGithubWebhook(commentPayload(action: $action), event: 'issue_comment')
        ->assertSuccessful()
        ->assertJson(['status' => 'ignored']);

    expect($this->helpRequest->replies()->count())->toBe(0);
    Notification::assertNothingSent();
})->with(['created', 'edited', 'deleted']);

it('resolves the request without a closing note when the issue is closed with a comment', function (): void {
    Notification::fake();
    Http::fake();

    // Closing with a comment fires the comment webhook first, then the close.
    postGithubWebhook(commentPayload(['body' => 'Deployed the fix.']), event: 'issue_comment')->assertSuccessful();

    postGithubWebhook([
        'action' => 'closed',
        'issue' => ['number' => 42, 'state' => 'closed', 'state_reason' => 'completed'],
        'repository' => ['full_name' => 'acme/app'],
    ])->assertSuccessful();

    expect($this->helpRequest->refresh()->status)->toBe(HelpRequestStatus::Resolved);
    expect($this->helpRequest->replies()->count())->toBe(0);

    Notification::assertSentTo($this->requester, HelpRequestResolvedNotification::class,
        fn (HelpRequestResolvedNotification $n): bool => $n->comment === null);

    // The comment thread is never read — closing must not call the comments API.
    Http::assertNothingSent();
});

it('closes the request without a note', function (): void {
    Notification::fake();

    postGithubWebhook([
        'action' => 'closed',
        'issue' => ['number' => 42, 'state' => 'closed', 'state_reason' => 'not_planned'],
        'repository' => ['full_name' => 'acme/app'],
    ])->assertSuccessful();

    expect($this->helpRequest->refresh()->status)->toBe(HelpRequestStatus::Closed);

    Notification::assertSentTo($this->requester, HelpRequestResolvedNotification::class,
        fn (HelpRequestResolvedNotification $n): bool => $n->comment === null);
});

it('posts an app reply to github as a comment', function (): void {
    Http::fake([
        'api.github.com/repos/*/issues/42/comments' => Http::response(['id' => 7777], 201),
    ]);

    $author = User::factory()->create(['name' => 'Joel Seneque']);

    $reply = HelpRequestReply::factory()->create([
        'help_request_id' => $this->helpRequest->id,
        'user_id' => $author->id,
        'body' => 'We are on it.',
    ]);

    $result = app(GitHubIssueService::class)->postComment($reply);

    expect($result['ok'])->toBeTrue();
    expect($reply->refresh()->github_comment_id)->toBe(7777);

    Http::assertSent(fn ($request) => str_contains($request['body'], 'We are on it.')
        && str_contains($request['body'], 'Joel Seneque'));
});

it('includes an attached screenshot in the github comment', function (): void {
    Http::fake([
        'api.github.com/repos/*/issues/42/comments' => Http::response(['id' => 7778], 201),
    ]);

    $reply = HelpRequestReply::factory()->withScreenshot()->create([
        'help_request_id' => $this->helpRequest->id,
        'user_id' => User::factory()->create()->id,
        'body' => 'Here is the screen I am seeing.',
    ]);

    expect(app(GitHubIssueService::class)->postComment($reply)['ok'])->toBeTrue();

    Http::assertSent(fn ($request) => str_contains($request['body'], '![Screenshot]('.$reply->getScreenshotUrl().')'));
});

it('never posts a reply to github twice', function (): void {
    Http::fake();

    $reply = HelpRequestReply::factory()->create([
        'help_request_id' => $this->helpRequest->id,
        'user_id' => null,
        'github_comment_id' => 9001,
    ]);

    expect(app(GitHubIssueService::class)->postComment($reply)['ok'])->toBeFalse();

    Http::assertNothingSent();
});

it('renders the resolution email with the closing note', function (): void {
    $this->helpRequest->update(['status' => HelpRequestStatus::Resolved]);

    $comment = HelpRequestReply::factory()->create([
        'help_request_id' => $this->helpRequest->id,
        'body' => 'Fixed in this morning\'s release.',
    ]);

    (new HelpRequestResolvedMail($this->helpRequest->refresh(), $comment))
        ->assertSeeInHtml('has been resolved')
        ->assertSeeInHtml('Closing note')
        ->assertSeeInHtml("Fixed in this morning's release.");
});

it('renders the resolution email without a closing note', function (): void {
    $this->helpRequest->update(['status' => HelpRequestStatus::Closed]);

    (new HelpRequestResolvedMail($this->helpRequest->refresh()))
        ->assertSeeInHtml('has been closed')
        ->assertDontSeeInHtml('Closing note');
});
