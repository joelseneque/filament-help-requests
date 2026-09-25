<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\Livewire\HelpRequestWidget;
use Joelseneque\HelpRequests\Mail\HelpRequestSubmittedMail;
use Joelseneque\HelpRequests\Mail\HelpRequestUserRepliedMail;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Tests\Fixtures\User;
use Livewire\Livewire;

it('submits a help request and emails the recipient', function () {
    Mail::fake();
    $user = User::factory()->create(['name' => 'Dana Scully']);
    $this->actingAs($user);

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'The quote page will not load for me.')
        ->set('pageUrl', 'https://example.test/quotes')
        ->set('pageTitle', 'Quotes')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('tab', 'mine');

    $request = HelpRequest::query()->firstOrFail();

    expect($request->user_id)->toBe($user->id)
        ->and($request->comment)->toBe('The quote page will not load for me.')
        ->and($request->page_url)->toBe('https://example.test/quotes')
        ->and($request->page_title)->toBe('Quotes')
        ->and($request->status)->toBe(HelpRequestStatus::Open);

    Mail::assertSent(HelpRequestSubmittedMail::class, function (HelpRequestSubmittedMail $mail) {
        return $mail->hasTo(config('help-requests.recipient'));
    });
});

it('requires a comment', function () {
    Mail::fake();
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', '')
        ->call('submit')
        ->assertHasErrors(['comment' => 'required']);

    expect(HelpRequest::count())->toBe(0);
    Mail::assertNothingSent();
});

it('stores an optional screenshot on the public disk', function () {
    Mail::fake();
    Storage::fake('public');
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'See attached screenshot of the error.')
        ->set('screenshot', UploadedFile::fake()->image('error.png'))
        ->call('submit')
        ->assertHasNoErrors();

    $request = HelpRequest::query()->firstOrFail();

    expect($request->screenshot_path)->not->toBeNull();
    Storage::disk('public')->assertExists($request->screenshot_path);
});

it('only lists the current user\'s requests', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    HelpRequest::factory()->count(2)->create(['user_id' => $user->id]);
    HelpRequest::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user);

    $component = Livewire::test(HelpRequestWidget::class);

    expect($component->instance()->myRequests())->toHaveCount(2);
});

it('lets a user reply to their own request and notifies the recipient', function () {
    Mail::fake();
    $user = User::factory()->create();
    $request = HelpRequest::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    Livewire::test(HelpRequestWidget::class)
        ->set('tab', 'mine')
        ->set("replyBody.{$request->id}", 'Thanks, that worked!')
        ->call('replyTo', $request->id)
        ->assertHasNoErrors();

    expect($request->replies()->count())->toBe(1)
        ->and($request->replies()->first()->user_id)->toBe($user->id)
        ->and($request->replies()->first()->body)->toBe('Thanks, that worked!');

    Mail::assertSent(HelpRequestUserRepliedMail::class, function (HelpRequestUserRepliedMail $mail) {
        return $mail->hasTo(config('help-requests.recipient'));
    });
});

it('stores an optional screenshot attached to a reply', function () {
    Mail::fake();
    Storage::fake('public');
    $user = User::factory()->create();
    $request = HelpRequest::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    Livewire::test(HelpRequestWidget::class)
        ->set('tab', 'mine')
        ->set("replyBody.{$request->id}", 'Here is what I see.')
        ->set("replyScreenshot.{$request->id}", UploadedFile::fake()->image('reply.png'))
        ->call('replyTo', $request->id)
        ->assertHasNoErrors()
        ->assertSet("replyScreenshot.{$request->id}", null);

    $reply = $request->replies()->firstOrFail();

    expect($reply->screenshot_path)->not->toBeNull();
    Storage::disk('public')->assertExists($reply->screenshot_path);
});

it('rejects a reply attachment that is not an image', function () {
    Mail::fake();
    Storage::fake('public');
    $user = User::factory()->create();
    $request = HelpRequest::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    Livewire::test(HelpRequestWidget::class)
        ->set('tab', 'mine')
        ->set("replyBody.{$request->id}", 'Attaching the log file.')
        ->set("replyScreenshot.{$request->id}", UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'))
        ->call('replyTo', $request->id)
        ->assertHasErrors(["replyScreenshot.{$request->id}" => 'image']);

    expect($request->replies()->count())->toBe(0);
    Mail::assertNothingSent();
});

it('keeps a reply screenshot optional', function () {
    Mail::fake();
    Storage::fake('public');
    $user = User::factory()->create();
    $request = HelpRequest::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    Livewire::test(HelpRequestWidget::class)
        ->set('tab', 'mine')
        ->set("replyBody.{$request->id}", 'No screenshot needed.')
        ->call('replyTo', $request->id)
        ->assertHasNoErrors();

    expect($request->replies()->firstOrFail()->screenshot_path)->toBeNull();
});

it('does not let a user reply to someone else\'s request', function () {
    Mail::fake();
    $user = User::factory()->create();
    $request = HelpRequest::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($user);

    Livewire::test(HelpRequestWidget::class)
        ->set("replyBody.{$request->id}", 'Sneaky reply')
        ->call('replyTo', $request->id)
        ->assertNotFound();

    expect($request->replies()->count())->toBe(0);
    Mail::assertNothingSent();
});
