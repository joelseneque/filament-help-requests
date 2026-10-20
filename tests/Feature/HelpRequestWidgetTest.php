<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\Livewire\HelpRequestWidget;
use Joelseneque\HelpRequests\Mail\HelpRequestSubmittedMail;
use Joelseneque\HelpRequests\Mail\HelpRequestUserRepliedMail;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestReply;
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

it('stores screenshots and documents together, keeping their names', function () {
    Mail::fake();
    Storage::fake('public');
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'Broken data attached.')
        ->set('screenshots.0', UploadedFile::fake()->image('error.png'))
        ->set('screenshots.1', UploadedFile::fake()->create('export.xlsx', 20, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))
        ->set('screenshotNotes.0', ['Total is wrong'])
        ->call('submit')
        ->assertHasNoErrors();

    $request = HelpRequest::query()->firstOrFail();

    expect(array_map($request->attachmentName(...), $request->attachmentPaths()))->toBe(['error.png', 'export.xlsx'])
        ->and($request->imageAttachmentPaths())->toHaveCount(1)
        ->and($request->fileAttachmentPaths())->toHaveCount(1)
        ->and($request->comment)->toBe("Broken data attached.\n\nMarked on screenshot:\n1. Total is wrong");
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

    expect($request->attachmentPaths())->toHaveCount(1);
    Storage::disk('public')->assertExists($request->attachmentPaths()[0]);
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

    expect($reply->attachmentPaths())->toHaveCount(1);
    Storage::disk('public')->assertExists($reply->attachmentPaths()[0]);
});

it('stores a document attached to a reply under its original name', function () {
    Mail::fake();
    Storage::fake('public');
    $user = User::factory()->create();
    $request = HelpRequest::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    Livewire::test(HelpRequestWidget::class)
        ->set('tab', 'mine')
        ->set("replyBody.{$request->id}", 'Attaching the signed quote.')
        ->set("replyScreenshots.{$request->id}.0", UploadedFile::fake()->create('Signed quote.pdf', 20, 'application/pdf'))
        ->call('replyTo', $request->id)
        ->assertHasNoErrors();

    $reply = $request->replies()->firstOrFail();
    [$path] = $reply->attachmentPaths();

    expect($reply->attachmentName($path))->toBe('Signed quote.pdf')
        ->and($reply->isImageAttachment($path))->toBeFalse();
    Storage::disk('public')->assertExists($path);
});

it('rejects a page disguised by its content as an accepted type', function (string $name, string $detectedMime) {
    Mail::fake();
    Storage::fake('public');
    $user = User::factory()->create();
    $request = HelpRequest::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user);

    // Content sniffing reads these as an accepted type; the extension is what a web server would serve them by.
    Livewire::test(HelpRequestWidget::class)
        ->set('tab', 'mine')
        ->set("replyBody.{$request->id}", 'Attaching a page.')
        ->set("replyScreenshots.{$request->id}.0", UploadedFile::fake()->create($name, 1)->mimeType($detectedMime))
        ->call('replyTo', $request->id)
        ->assertHasErrors(["replyScreenshots.{$request->id}.0" => 'extensions']);

    expect($request->replies()->count())->toBe(0);
    Storage::disk('public')->assertDirectoryEmpty('help-requests');
})->with([
    'html read as text' => ['notes.html', 'text/plain'],
    'svg read as pdf' => ['drawing.svg', 'application/pdf'],
]);

it('rejects an svg or html upload by content type too', function (string $name, string $mime) {
    Mail::fake();
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'Attaching a page.')
        ->set('screenshots.0', UploadedFile::fake()->create($name, 1, $mime))
        ->call('submit')
        ->assertHasErrors(['screenshots.0' => 'mimetypes'])
        ->assertSee('Attach an image, PDF, Word, Excel, CSV or text file.');

    expect(HelpRequest::count())->toBe(0);
})->with([
    'svg' => ['drawing.svg', 'image/svg+xml'],
    'html' => ['page.html', 'text/html'],
]);

it('stores uploads under a url-safe version of their name', function () {
    Mail::fake();
    Storage::fake('public');
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'Invoice attached.')
        ->set('screenshots.0', UploadedFile::fake()->create('Invoice #12 50% <final>.pdf', 20, 'application/pdf'))
        ->call('submit')
        ->assertHasNoErrors();

    [$path] = HelpRequest::query()->firstOrFail()->attachmentPaths();

    expect(basename($path))->toBe('Invoice -12 50- -final.pdf');
    Storage::disk('public')->assertExists($path);
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

    expect($request->replies()->firstOrFail()->hasAttachments())->toBeFalse();
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

    expect($request->attachmentPaths())->toHaveCount(2)
        ->and($request->comment)->toBe(
            "Two places look wrong.\n\nMarked on screenshot 1:\n1. Header\n\nMarked on screenshot 2:\n2. Footer"
        );

    foreach ($request->attachmentPaths() as $path) {
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

it('moves screenshots stored before attachments into the attachments list', function () {
    $migration = include __DIR__.'/../../database/migrations/add_attachments_to_help_requests_tables.php.stub';
    $migration->down();

    $request = HelpRequest::factory()->create();
    DB::table('help_requests')->where('id', $request->id)->update([
        'screenshot_path' => 'help-requests/old.png',
        'screenshot_paths' => json_encode(['help-requests/a.png', 'help-requests/b.png']),
    ]);
    $reply = HelpRequestReply::factory()->create(['help_request_id' => $request->id]);
    DB::table('help_request_replies')->where('id', $reply->id)->update(['screenshot_path' => 'help-requests/reply.png']);

    $migration->up();

    expect(Schema::hasColumns('help_requests', ['screenshot_path', 'screenshot_paths']))->toBeFalse()
        ->and($request->refresh()->attachmentPaths())->toBe(['help-requests/old.png', 'help-requests/a.png', 'help-requests/b.png'])
        ->and($reply->refresh()->attachmentPaths())->toBe(['help-requests/reply.png']);
});
