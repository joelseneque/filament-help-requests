<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Pages\ListHelpRequests;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Livewire\HelpRequestWidget;
use Joelseneque\HelpRequests\Mail\HelpRequestSubmittedMail;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Services\GitHubIssueService;
use Joelseneque\HelpRequests\Tests\Fixtures\User;
use Livewire\Livewire;

beforeEach(function (): void {
    Mail::fake();
});

it('offers the default feedback categories', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->assertSee(['Change this', 'Something missing', 'Looks broken', 'Confusing', 'This works well']);
});

it('stores the chosen category and the page name', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('category', 'confusing')
        ->set('pageTitle', 'Deals — record')
        ->set('comment', 'I cannot tell which total is which.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('category', '');

    $request = HelpRequest::query()->sole();

    expect($request->category)->toBe('confusing')
        ->and($request->categoryLabel())->toBe('Confusing')
        ->and($request->page_title)->toBe('Deals — record');
});

it('keeps the category optional', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'Just a question.')
        ->call('submit')
        ->assertHasNoErrors();

    expect(HelpRequest::query()->sole()->category)->toBeNull();
});

it('rejects a category that is not offered', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('category', 'made_up')
        ->set('comment', 'Sneaky category.')
        ->call('submit')
        ->assertHasErrors(['category' => 'in']);

    expect(HelpRequest::count())->toBe(0);
});

it('lets the app replace the categories with its own list', function () {
    HelpRequests::useCategories(['idea' => 'I have an idea', 'Needs a fix']);

    expect(HelpRequests::categories())->toBe([
        'idea' => 'I have an idea',
        'needs_a_fix' => 'Needs a fix',
    ]);

    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->assertSee(['I have an idea', 'Needs a fix'])
        ->assertDontSee('Looks broken')
        ->set('category', 'needs_a_fix')
        ->set('comment', 'The export button is greyed out.')
        ->call('submit')
        ->assertHasNoErrors();

    expect(HelpRequest::query()->sole()->category)->toBe('needs_a_fix');
});

it('hides the category choice when given an empty list', function () {
    HelpRequests::useCategories([]);

    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->assertDontSee('radiogroup', escape: false)
        ->assertDontSee('This works well');
});

it('still labels a stored category that is no longer offered', function () {
    HelpRequests::useCategories(['idea' => 'I have an idea']);

    expect(HelpRequest::factory()->make(['category' => 'looks_broken'])->categoryLabel())->toBe('Looks Broken');
});

it('shows and filters by category in the admin list', function () {
    $this->actingAs(User::factory()->create());

    $broken = HelpRequest::factory()->create(['category' => 'broken']);
    $praise = HelpRequest::factory()->create(['category' => 'works_well']);

    Livewire::test(ListHelpRequests::class)
        ->assertSee('Looks broken')
        ->filterTable('category', 'broken')
        ->assertCanSeeTableRecords([$broken])
        ->assertCanNotSeeTableRecords([$praise]);
});

it('includes the category in the admin email', function () {
    $request = HelpRequest::factory()->create(['category' => 'missing']);

    (new HelpRequestSubmittedMail($request))->assertSeeInHtml('Something missing');
});

it('includes the category in the github issue', function () {
    makeGitHubSettings();
    Http::fake(['api.github.com/*' => Http::response(['number' => 7, 'state' => 'open', 'html_url' => 'https://github.com/acme/app/issues/7'], 201)]);

    $request = HelpRequest::factory()->create(['category' => 'broken', 'comment' => 'The chart is empty.']);

    app(GitHubIssueService::class)->createIssue($request);

    Http::assertSent(fn ($sent): bool => str_contains($sent['title'], '[Looks broken]')
        && str_contains($sent['body'], '**Type:** Looks broken'));
});
