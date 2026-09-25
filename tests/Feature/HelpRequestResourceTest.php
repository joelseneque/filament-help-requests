<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\HelpRequestResource;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Pages\ListHelpRequests;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Pages\ViewHelpRequest;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestReply;
use Joelseneque\HelpRequests\Notifications\HelpRequestReplyNotification;
use Joelseneque\HelpRequests\Notifications\HelpRequestResolvedNotification;
use Joelseneque\HelpRequests\Support\HelpRequestNotifier;
use Joelseneque\HelpRequests\Tests\Fixtures\User;
use Livewire\Livewire;

it('lets an admin see the help requests list', function () {
    $this->actingAs(User::factory()->create());

    $requests = HelpRequest::factory()->count(3)->create();

    Livewire::test(ListHelpRequests::class)
        ->assertOk()
        ->assertCanSeeTableRecords($requests);
});

it('hides help requests from non-admin users', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    expect(HelpRequestResource::canViewAny())->toBeFalse();
});

it('allows an admin to reply, update status and email the requester', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $requester = User::factory()->create(['email' => 'requester@example.com']);
    $request = HelpRequest::factory()->create([
        'user_id' => $requester->id,
        'status' => HelpRequestStatus::Open,
    ]);

    $this->actingAs($admin);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->callAction(TestAction::make('reply'), [
            'body' => 'We have deployed a fix, please try again.',
            'status' => HelpRequestStatus::Resolved->value,
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    $request->refresh();

    expect($request->status)->toBe(HelpRequestStatus::Resolved)
        ->and($request->resolved_at)->not->toBeNull()
        ->and($request->replies)->toHaveCount(1)
        ->and($request->replies->first()->user_id)->toBe($admin->id)
        ->and($request->replies->first()->body)->toBe('We have deployed a fix, please try again.');

    // Replying and resolving in one go sends the resolution notification carrying
    // the reply as its closing note, rather than two emails about the same text.
    Notification::assertSentTo($requester, HelpRequestResolvedNotification::class,
        fn (HelpRequestResolvedNotification $n, array $channels): bool => in_array('mail', $channels, true)
            && $n->comment?->body === 'We have deployed a fix, please try again.');

    Notification::assertNotSentTo($requester, HelpRequestReplyNotification::class);
});

it('lets an admin attach a screenshot to their reply', function () {
    Notification::fake();
    Storage::fake('public');

    $admin = User::factory()->create();
    $request = HelpRequest::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => HelpRequestStatus::Open,
    ]);

    $this->actingAs($admin);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->callAction(TestAction::make('reply'), [
            'body' => 'This is the screen you should be seeing.',
            'screenshot_path' => UploadedFile::fake()->image('fixed.png'),
            'status' => HelpRequestStatus::InProgress->value,
        ])
        ->assertHasNoActionErrors();

    $reply = $request->replies()->firstOrFail();

    expect($reply->screenshot_path)->not->toBeNull()
        ->and($reply->hasScreenshot())->toBeTrue();

    Storage::disk('public')->assertExists($reply->screenshot_path);
});

it('renders an attached reply screenshot in the conversation', function () {
    Storage::fake('public');

    $this->actingAs(User::factory()->create());

    $request = HelpRequest::factory()->create();
    $path = UploadedFile::fake()->image('reply.png')->store('help-requests', 'public');

    HelpRequestReply::factory()->create([
        'help_request_id' => $request->id,
        'body' => 'Have a look at this.',
        'screenshot_path' => $path,
    ]);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->assertOk()
        ->assertSee(Storage::disk('public')->url($path), escape: false);
});

it('leaves the reply screenshot empty when none is attached', function () {
    Notification::fake();
    Storage::fake('public');

    $admin = User::factory()->create();
    $request = HelpRequest::factory()->create([
        'user_id' => User::factory()->create()->id,
        'status' => HelpRequestStatus::Open,
    ]);

    $this->actingAs($admin);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->callAction(TestAction::make('reply'), [
            'body' => 'No screenshot with this one.',
            'status' => HelpRequestStatus::InProgress->value,
        ])
        ->assertHasNoActionErrors();

    expect($request->replies()->firstOrFail()->screenshot_path)->toBeNull();
});

it('emails the reply on its own when the status is not being finished', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $requester = User::factory()->create(['email' => 'requester@example.com']);
    $request = HelpRequest::factory()->create([
        'user_id' => $requester->id,
        'status' => HelpRequestStatus::Open,
    ]);

    $this->actingAs($admin);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->callAction(TestAction::make('reply'), [
            'body' => 'Can you tell us which page this happened on?',
            'status' => HelpRequestStatus::InProgress->value,
        ])
        ->assertHasNoActionErrors();

    Notification::assertSentTo($requester, HelpRequestReplyNotification::class,
        fn (HelpRequestReplyNotification $n, array $channels): bool => in_array('mail', $channels, true));
    Notification::assertNotSentTo($requester, HelpRequestResolvedNotification::class);
});

it('emails a closing comment when the status is set to resolved', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $requester = User::factory()->create(['email' => 'requester@example.com']);
    $request = HelpRequest::factory()->create([
        'user_id' => $requester->id,
        'status' => HelpRequestStatus::Open,
    ]);

    $this->actingAs($admin);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->callAction(TestAction::make('updateStatus'), [
            'status' => HelpRequestStatus::Resolved->value,
            'comment' => 'Fixed in this morning\'s release.',
        ])
        ->assertHasNoActionErrors();

    expect($request->refresh()->replies)->toHaveCount(1);

    Notification::assertSentTo($requester, HelpRequestResolvedNotification::class,
        fn (HelpRequestResolvedNotification $n): bool => $n->comment?->body === 'Fixed in this morning\'s release.');
});

it('still emails on resolve when no comment is given', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $requester = User::factory()->create(['email' => 'requester@example.com']);
    $request = HelpRequest::factory()->create([
        'user_id' => $requester->id,
        'status' => HelpRequestStatus::Open,
    ]);

    $this->actingAs($admin);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->callAction(TestAction::make('updateStatus'), [
            'status' => HelpRequestStatus::Resolved->value,
            'comment' => null,
        ])
        ->assertHasNoActionErrors();

    expect($request->refresh()->replies)->toHaveCount(0);

    Notification::assertSentTo($requester, HelpRequestResolvedNotification::class,
        fn (HelpRequestResolvedNotification $n, array $channels): bool => $n->comment === null
            && in_array('mail', $channels, true));
});

it('does not email again when an already resolved request is resolved', function () {
    Notification::fake();

    $admin = User::factory()->create();
    $requester = User::factory()->create();
    $request = HelpRequest::factory()->create([
        'user_id' => $requester->id,
        'status' => HelpRequestStatus::Resolved,
    ]);

    $this->actingAs($admin);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->callAction(TestAction::make('updateStatus'), [
            'status' => HelpRequestStatus::Resolved->value,
            'comment' => null,
        ])
        ->assertHasNoActionErrors();

    Notification::assertNotSentTo($requester, HelpRequestResolvedNotification::class);
});

it('lets the app take over requester notification delivery', function () {
    Notification::fake();

    // e.g. a user who chose "in-app only": the app delivers the database leg alone.
    HelpRequests::sendNotificationsUsing(function (object $user, $notification, string $type, array $context): void {
        expect($type)->toBe(HelpRequestNotifier::TYPE_RESOLVED)
            ->and($context['comment_excerpt'])->toBe('All sorted.');

        $user->notify($notification->onlyVia(['database']));
    });

    $admin = User::factory()->create();
    $requester = User::factory()->create(['email' => 'requester@example.com']);
    $request = HelpRequest::factory()->create([
        'user_id' => $requester->id,
        'status' => HelpRequestStatus::Open,
    ]);

    $this->actingAs($admin);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->callAction(TestAction::make('updateStatus'), [
            'status' => HelpRequestStatus::Resolved->value,
            'comment' => 'All sorted.',
        ])
        ->assertHasNoActionErrors();

    Notification::assertSentTo($requester, HelpRequestResolvedNotification::class,
        fn (HelpRequestResolvedNotification $n, array $channels): bool => $channels === ['database']);

    Notification::assertNotSentTo($requester, HelpRequestResolvedNotification::class,
        fn (HelpRequestResolvedNotification $n, array $channels): bool => in_array('mail', $channels, true));
});

it('uses a custom authorization callback', function () {
    HelpRequests::authorizeUsing(fn (object $user): bool => $user->email === 'boss@example.com');

    $this->actingAs(User::factory()->create(['email' => 'someone@example.com', 'is_admin' => true]));
    expect(HelpRequestResource::canViewAny())->toBeFalse();

    $this->actingAs(User::factory()->create(['email' => 'boss@example.com', 'is_admin' => false]));
    expect(HelpRequestResource::canViewAny())->toBeTrue();
});

it('allows an admin to update status without replying', function () {
    $admin = User::factory()->create();
    $request = HelpRequest::factory()->create(['status' => HelpRequestStatus::Open]);

    $this->actingAs($admin);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->callAction(TestAction::make('updateStatus'), [
            'status' => HelpRequestStatus::InProgress->value,
        ])
        ->assertHasNoActionErrors();

    expect($request->refresh()->status)->toBe(HelpRequestStatus::InProgress)
        ->and($request->replies)->toHaveCount(0);
});
