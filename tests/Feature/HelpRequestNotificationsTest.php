<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Mail;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Pages\ViewHelpRequest;
use Joelseneque\HelpRequests\Livewire\HelpRequestWidget;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestReply;
use Joelseneque\HelpRequests\Notifications\HelpRequestReplyNotification;
use Joelseneque\HelpRequests\Notifications\HelpRequestResolvedNotification;
use Joelseneque\HelpRequests\Tests\Fixtures\User;
use Livewire\Livewire;

function recipientAdmin(): User
{
    return User::factory()->create([
        'email' => config('help-requests.recipient'),
    ]);
}

it('notifies the admin when a request is submitted', function () {
    Mail::fake();
    $admin = recipientAdmin();
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user);

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'The dashboard is broken for me.')
        ->call('submit')
        ->assertHasNoErrors();

    expect($admin->fresh()->unreadNotifications)->toHaveCount(1);
});

it('notifies the admin when the user replies', function () {
    Mail::fake();
    $admin = recipientAdmin();
    $user = User::factory()->create(['is_admin' => false]);
    $request = HelpRequest::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    Livewire::test(HelpRequestWidget::class)
        ->set("replyBody.{$request->id}", 'Still not working.')
        ->call('replyTo', $request->id)
        ->assertHasNoErrors();

    expect($admin->fresh()->unreadNotifications)->toHaveCount(1);
});

it('notifies the requester when an admin replies', function () {
    Mail::fake();
    $admin = User::factory()->create();
    $requester = User::factory()->create([
        'is_admin' => false,
        'email' => 'requester@example.com',
    ]);
    $request = HelpRequest::factory()->create([
        'user_id' => $requester->id,
        'status' => HelpRequestStatus::Open,
    ]);

    $this->actingAs($admin);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->callAction(TestAction::make('reply'), [
            'body' => 'This should be fixed now.',
            'status' => HelpRequestStatus::Resolved->value,
        ])
        ->assertHasNoActionErrors();

    expect($requester->fresh()->unreadNotifications)->toHaveCount(1);
});

/*
 * `HelpRequestResource` is admin-only, but the reply and resolved notifications
 * go to the person who raised the request — usually a Creator, who holds no help
 * request permissions at all. "View request" was sending them to a 403 rather
 * than to their thread. Help request #41.
 */

it('sends a non-admin requester to their own thread, not the admin resource', function (string $class) {
    $requester = User::factory()->create(['is_admin' => false]);
    $request = HelpRequest::factory()->create(['user_id' => $requester->id]);
    $reply = HelpRequestReply::factory()->create(['help_request_id' => $request->id]);

    $notification = $class === HelpRequestReplyNotification::class
        ? new HelpRequestReplyNotification($request, $reply)
        : new HelpRequestResolvedNotification($request, $reply);

    $url = $notification->toDatabase($requester)['actions'][0]['url'];

    expect($url)->toContain('help_request='.$request->id)
        ->and($url)->not->toContain('/help-requests/');
})->with([
    'a reply' => [HelpRequestReplyNotification::class],
    'a resolution' => [HelpRequestResolvedNotification::class],
]);

it('still sends an admin requester to the triage page', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $request = HelpRequest::factory()->create(['user_id' => $admin->id]);
    $reply = HelpRequestReply::factory()->create(['help_request_id' => $request->id]);

    $url = (new HelpRequestReplyNotification($request, $reply))->toDatabase($admin)['actions'][0]['url'];

    expect($url)->toContain('/help-requests/'.$request->id)
        ->and($url)->not->toContain('help_request=');
});

it('opens the widget on the linked thread', function () {
    $requester = User::factory()->create(['is_admin' => false]);
    $request = HelpRequest::factory()->create(['user_id' => $requester->id]);

    $this->actingAs($requester);

    /*
     * Driven through the component rather than over HTTP: the panel enforces
     * MFA, so a plain `get('/')` is a redirect to the set-up page long before
     * the widget renders (see ExampleTest). `withQueryParams` reaches the same
     * `mount()` the panel page would.
     */
    Livewire::withQueryParams(['help_request' => $request->id])
        ->test(HelpRequestWidget::class)
        ->assertSet('open', true)
        ->assertSet('tab', 'mine')
        ->assertSet('highlightRequestId', $request->id);
});

/* Somebody else's request id must not open, or reveal, anything. */
it('ignores a help request id that is not the viewer own', function () {
    $requester = User::factory()->create(['is_admin' => false]);
    $someoneElse = HelpRequest::factory()->create([
        'user_id' => User::factory()->create()->id,
    ]);

    $this->actingAs($requester);

    Livewire::withQueryParams(['help_request' => $someoneElse->id])
        ->test(HelpRequestWidget::class)
        ->assertSet('open', false)
        ->assertSet('highlightRequestId', null);
});

it('ignores a missing or unparseable help request id', function (mixed $value) {
    $requester = User::factory()->create(['is_admin' => false]);
    $this->actingAs($requester);

    Livewire::withQueryParams(['help_request' => $value])
        ->test(HelpRequestWidget::class)
        ->assertSet('open', false)
        ->assertSet('highlightRequestId', null);
})->with([
    'a word' => ['not-an-id'],
    'a zero' => ['0'],
    'an empty string' => [''],
]);
