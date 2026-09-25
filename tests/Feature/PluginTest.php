<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Livewire\HelpRequestWidget;
use Joelseneque\HelpRequests\Mail\HelpRequestSubmittedMail;
use Joelseneque\HelpRequests\Tests\Fixtures\User;
use Livewire\Livewire;

it('renders the help widget on every panel page', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]));

    $this->get('/admin')
        ->assertOk()
        ->assertSeeLivewire('help-requests-widget');
});

it('accepts the github webhook without a csrf token or session', function () {
    makeGitHubSettings();

    postGithubWebhook(['zen' => 'Keep it logically awesome.'], event: 'ping')
        ->assertOk()
        ->assertJson(['status' => 'pong']);
});

it('schedules the hourly github sync', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'help-requests:sync-github'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});

it('skips the admin email when no recipient is configured', function () {
    Mail::fake();
    config()->set('help-requests.recipient', null);

    $this->actingAs(User::factory()->create(['is_admin' => false]));

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'Nothing loads.')
        ->call('submit')
        ->assertHasNoErrors();

    Mail::assertNotSent(HelpRequestSubmittedMail::class);
});

it('lets the app choose who hears about new requests', function () {
    Mail::fake();
    $support = User::factory()->create(['email' => 'desk@example.com']);
    HelpRequests::resolveAdminRecipientsUsing(fn () => [$support]);

    $this->actingAs(User::factory()->create(['is_admin' => false]));

    Livewire::test(HelpRequestWidget::class)
        ->set('comment', 'Nothing loads.')
        ->call('submit');

    expect($support->fresh()->unreadNotifications)->toHaveCount(1);
});

it('reads a display name from first and last name columns when there is no name', function () {
    $user = new class
    {
        public ?string $first_name = 'Dana';

        public ?string $last_name = 'Scully';

        public ?string $email = 'dana@example.com';
    };

    expect(HelpRequests::userName($user))->toBe('Dana Scully')
        ->and(HelpRequests::userFirstName($user))->toBe('Dana');
});
