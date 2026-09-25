<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Pages\ViewHelpRequest;
use Joelseneque\HelpRequests\HelpRequestsPlugin;
use Joelseneque\HelpRequests\Livewire\HelpRequestWidget;
use Joelseneque\HelpRequests\Mail\HelpRequestSubmittedMail;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Services\GitHubIssueService;
use Joelseneque\HelpRequests\Tests\Fixtures\User;
use Livewire\Livewire;

beforeEach(function (): void {
    Mail::fake();
});

const LOOM_URL = 'https://www.loom.com/share/0281766fa2d04bb788eaf19e65135184';

it('offers a loom field with a link to record one', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->assertSee('Loom video')
        ->assertSee('Record a Loom')
        ->assertSeeHtml('href="https://www.loom.com/"');
});

it('stores a loom link with the request', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'Watch what happens when I save.')
        ->set('videoUrl', LOOM_URL)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('videoUrl', '');

    $request = HelpRequest::query()->sole();

    expect($request->video_url)->toBe(LOOM_URL)
        ->and($request->isLoomVideo())->toBeTrue()
        ->and($request->videoEmbedUrl())->toBe('https://www.loom.com/embed/0281766fa2d04bb788eaf19e65135184');
});

it('rejects links from other hosts or without https', function (string $url) {
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'Here is a video.')
        ->set('videoUrl', $url)
        ->call('submit')
        ->assertHasErrors('videoUrl');

    expect(HelpRequest::count())->toBe(0);
})->with([
    'another site' => ['https://evil.example.com/share/abc'],
    'a lookalike host' => ['https://notloom.com/share/abc'],
    'plain http' => ['http://www.loom.com/share/abc'],
    'not a url' => ['loom video please'],
]);

it('accepts any https link when no hosts are restricted', function () {
    config()->set('help-requests.video.allowed_hosts', []);
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'Recorded with something else.')
        ->set('videoUrl', 'https://videos.example.com/v/123')
        ->call('submit')
        ->assertHasNoErrors();

    expect(HelpRequest::query()->sole()->videoEmbedUrl())->toBeNull();
});

it('hides the field when video links are switched off', function () {
    config()->set('help-requests.video.enabled', false);
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->assertDontSee('Record a Loom');
});

it('links to the video from the requester thread', function () {
    $user = User::factory()->create();
    HelpRequest::factory()->create(['user_id' => $user->id, 'video_url' => LOOM_URL]);

    $this->actingAs($user);

    Livewire::test(HelpRequestWidget::class)
        ->set('tab', 'mine')
        ->assertSee('Watch video')
        ->assertSeeHtml('href="'.LOOM_URL.'"');
});

it('embeds the loom player and an open-in-loom link on the admin page', function () {
    $this->actingAs(User::factory()->create());
    $request = HelpRequest::factory()->create(['video_url' => LOOM_URL]);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->assertSeeHtml('src="https://www.loom.com/embed/0281766fa2d04bb788eaf19e65135184"')
        ->assertSee('Open in Loom');
});

it('includes the video link in the admin email and the github issue', function () {
    $request = HelpRequest::factory()->create(['video_url' => LOOM_URL]);

    (new HelpRequestSubmittedMail($request))->assertSeeInHtml(LOOM_URL);

    makeGitHubSettings();
    Http::fake(['api.github.com/*' => Http::response(['number' => 3, 'state' => 'open'], 201)]);

    app(GitHubIssueService::class)->createIssue($request);

    Http::assertSent(fn ($sent): bool => str_contains($sent['body'], '**Video:** '.LOOM_URL));
});

it('lets the plugin switch the video field off and back on', function () {
    $this->actingAs(User::factory()->create());

    HelpRequestsPlugin::make()->videoLinks(false);

    Livewire::test(HelpRequestWidget::class)
        ->assertDontSee('Record a Loom')
        ->set('comment', 'No video field here.')
        ->set('videoUrl', 'https://www.loom.com/share/abc123')
        ->call('submit')
        ->assertHasNoErrors();

    expect(HelpRequest::query()->sole()->video_url)->toBeNull();

    HelpRequestsPlugin::make()->videoLinks();

    Livewire::test(HelpRequestWidget::class)->assertSee('Record a Loom');
});

it('tries the loom desktop app before the website', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->assertSeeHtml('appUrl: \'loomDesktop:\/\/\'')
        ->assertSeeHtml('href="https://www.loom.com/"');
});

it('goes straight to the website when no app url is configured', function () {
    config()->set('help-requests.video.record_app_url', null);
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->assertSeeHtml('appUrl: null')
        ->assertSeeHtml('href="https://www.loom.com/"');
});
