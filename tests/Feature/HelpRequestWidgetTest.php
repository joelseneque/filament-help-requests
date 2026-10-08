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
        ->set('screenshots.0', UploadedFile::fake()->image('error.png'))
        ->call('submit')
        ->assertHasNoErrors();

    $request = HelpRequest::query()->firstOrFail();

    expect($request->screenshotPaths())->toHaveCount(1);
    Storage::disk('public')->assertExists($request->screenshotPaths()[0]);
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
        ->set("replyScreenshots.{$request->id}.0", UploadedFile::fake()->image('reply.png'))
        ->call('replyTo', $request->id)
        ->assertHasNoErrors()
        ->assertSet("replyScreenshots.{$request->id}", null);

    $reply = $request->replies()->firstOrFail();

    expect($reply->screenshotPaths())->toHaveCount(1);
    Storage::disk('public')->assertExists($reply->screenshotPaths()[0]);
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
        ->set("replyScreenshots.{$request->id}.0", UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'))
        ->call('replyTo', $request->id)
        ->assertHasErrors(["replyScreenshots.{$request->id}.0" => 'image']);

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

    expect($request->replies()->firstOrFail()->hasScreenshot())->toBeFalse();
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

it('appends screenshot marker notes to the comment, keeping marker numbers', function () {
    Mail::fake();
    Storage::fake('public');
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'The totals are wrong.')
        ->set('screenshots.0', UploadedFile::fake()->image('marked.png'))
        ->set('screenshotNotes.0', ['Here is marked.', '', 'another mark here'])
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('screenshotNotes', []);

    expect(HelpRequest::query()->firstOrFail()->comment)->toBe(
        "The totals are wrong.\n\nMarked on screenshot:\n1. Here is marked.\n3. another mark here"
    );
});

it('drops marker notes when no screenshot is attached', function () {
    Mail::fake();
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'No picture this time.')
        ->set('screenshotNotes.0', ['Stale note'])
        ->call('submit')
        ->assertHasNoErrors();

    expect(HelpRequest::query()->firstOrFail()->comment)->toBe('No picture this time.');
});

it('rejects a marker note that is too long', function () {
    Mail::fake();
    Storage::fake('public');
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'Long note attached.')
        ->set('screenshots.0', UploadedFile::fake()->image('marked.png'))
        ->set('screenshotNotes.0', [str_repeat('a', 501)])
        ->call('submit')
        ->assertHasErrors(['screenshotNotes.0.0' => 'max']);

    expect(HelpRequest::count())->toBe(0);
});

it('appends screenshot marker notes to a reply', function () {
    Mail::fake();
    Storage::fake('public');
    $user = User::factory()->create();
    $request = HelpRequest::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    Livewire::test(HelpRequestWidget::class)
        ->set('tab', 'mine')
        ->set("replyBody.{$request->id}", 'Still broken.')
        ->set("replyScreenshots.{$request->id}.0", UploadedFile::fake()->image('reply.png'))
        ->set("replyScreenshotNotes.{$request->id}.0", ['This button'])
        ->call('replyTo', $request->id)
        ->assertHasNoErrors();

    expect($request->replies()->firstOrFail()->body)
        ->toBe("Still broken.\n\nMarked on screenshot:\n1. This button");
});

it('stores several screenshots, skipping removed ones, and numbers their notes', function () {
    Mail::fake();
    Storage::fake('public');
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'Two places look wrong.')
        ->set('screenshots.0', UploadedFile::fake()->image('first.png'))
        ->set('screenshots.1', UploadedFile::fake()->image('removed.png'))
        ->set('screenshots.2', UploadedFile::fake()->image('third.png'))
        ->set('screenshotNotes.0', ['Header'])
        ->set('screenshotNotes.1', ['Gone with its screenshot'])
        ->set('screenshotNotes.2', ['', 'Footer'])
        ->set('screenshots.1', null)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('screenshots', []);

    $request = HelpRequest::query()->firstOrFail();

    expect($request->screenshotPaths())->toHaveCount(2)
        ->and($request->comment)->toBe(
            "Two places look wrong.\n\nMarked on screenshot 1:\n1. Header\n\nMarked on screenshot 2:\n2. Footer"
        );

    foreach ($request->screenshotPaths() as $path) {
        Storage::disk('public')->assertExists($path);
    }
});

it('limits how many screenshots can be attached', function () {
    Mail::fake();
    Storage::fake('public');
    config()->set('help-requests.storage.max_files', 2);
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'Too many pictures.')
        ->set('screenshots.0', UploadedFile::fake()->image('a.png'))
        ->set('screenshots.1', UploadedFile::fake()->image('b.png'))
        ->set('screenshots.2', UploadedFile::fake()->image('c.png'))
        ->call('submit')
        ->assertHasErrors(['screenshots' => 'max']);

    expect(HelpRequest::count())->toBe(0);
});

it('still shows a screenshot stored before multiple screenshots', function () {
    Storage::fake('public');
    $request = HelpRequest::factory()->create(['screenshot_path' => 'help-requests/old.png']);

    expect($request->screenshotPaths())->toBe(['help-requests/old.png'])
        ->and($request->getScreenshotUrl())->toEndWith('help-requests/old.png');
});
