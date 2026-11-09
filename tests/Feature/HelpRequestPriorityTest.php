<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Joelseneque\HelpRequests\Enums\HelpRequestPriority;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Pages\ListHelpRequests;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Pages\ViewHelpRequest;
use Joelseneque\HelpRequests\Livewire\HelpRequestWidget;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Tests\Fixtures\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-11-09 10:00'));
});

it('works out the due date for each priority', function () {
    $from = Carbon::parse('2026-11-09 10:00');

    expect(HelpRequestPriority::High->dueAt($from)->toDateTimeString())->toBe('2026-11-09 23:59:59')
        ->and(HelpRequestPriority::Medium->dueAt($from)->toDateTimeString())->toBe('2026-11-16 10:00:00')
        ->and(HelpRequestPriority::Low->dueAt($from)->toDateTimeString())->toBe('2026-12-09 10:00:00')
        ->and($from->toDateTimeString())->toBe('2026-11-09 10:00:00');
});

it('does not overflow a month deadline past the end of a short month', function () {
    $from = Carbon::parse('2027-01-31 10:00');

    expect(HelpRequestPriority::Low->dueAt($from)->toDateTimeString())->toBe('2027-02-28 10:00:00');
});

it('defaults a new request to medium with a due date', function () {
    $request = HelpRequest::create(['comment' => 'Help']);

    expect($request->priority)->toBe(HelpRequestPriority::Medium)
        ->and($request->due_at->toDateTimeString())->toBe('2026-11-16 10:00:00');
});

it('bases the due date on an explicit created_at', function () {
    $request = HelpRequest::factory()->create([
        'priority' => HelpRequestPriority::Low,
        'created_at' => Carbon::parse('2026-10-01 08:00'),
    ]);

    expect($request->due_at->toDateTimeString())->toBe('2026-11-01 08:00:00');
});

it('keeps an explicit due date', function () {
    $due = Carbon::parse('2026-12-25 09:00');
    $request = HelpRequest::factory()->create(['due_at' => $due]);

    expect($request->fresh()->due_at->equalTo($due))->toBeTrue();
});

it('does not treat requests without a due date as overdue', function () {
    $request = HelpRequest::factory()->create();
    $request->forceFill(['due_at' => null])->save();
    $this->travel(60)->days();

    expect($request->isOverdue())->toBeFalse()
        ->and(HelpRequest::overdue()->count())->toBe(0);
});

it('flags open requests past their due date as overdue', function () {
    $request = HelpRequest::factory()->create(['priority' => HelpRequestPriority::High]);
    $this->travel(1)->days();

    expect($request->isOverdue())->toBeTrue()
        ->and(HelpRequest::overdue()->pluck('id')->all())->toBe([$request->id]);
});

it('does not flag finished requests as overdue', function (HelpRequestStatus $status) {
    $request = HelpRequest::factory()->create(['priority' => HelpRequestPriority::High, 'status' => $status]);
    $this->travel(1)->days();

    expect($request->isOverdue())->toBeFalse()
        ->and(HelpRequest::overdue()->count())->toBe(0);
})->with([HelpRequestStatus::Resolved, HelpRequestStatus::Closed]);

it('preselects medium and offers all three priorities', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->assertSet('priority', 'medium')
        ->assertSee(['High', 'today', 'within a week', 'within a month']);
});

it('stores the chosen priority and resets to medium', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('priority', 'high')
        ->set('comment', 'The quote page will not load.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('priority', 'medium');

    $request = HelpRequest::query()->sole();

    expect($request->priority)->toBe(HelpRequestPriority::High)
        ->and($request->due_at->toDateTimeString())->toBe('2026-11-09 23:59:59');
});

it('rejects a priority that is not offered', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(HelpRequestWidget::class)
        ->set('priority', 'urgent')
        ->set('comment', 'The quote page will not load.')
        ->call('submit')
        ->assertHasErrors(['priority']);

    expect(HelpRequest::query()->count())->toBe(0);
});

it('shows and filters by priority in the admin list', function () {
    $this->actingAs(User::factory()->create());

    $high = HelpRequest::factory()->create(['priority' => HelpRequestPriority::High]);
    $low = HelpRequest::factory()->create(['priority' => HelpRequestPriority::Low]);

    Livewire::test(ListHelpRequests::class)
        ->assertSee('High')
        ->filterTable('priority', 'high')
        ->assertCanSeeTableRecords([$high])
        ->assertCanNotSeeTableRecords([$low]);
});

it('filters the admin list to overdue requests', function () {
    $this->actingAs(User::factory()->create());

    $overdue = HelpRequest::factory()->create(['due_at' => now()->subDay()]);
    $future = HelpRequest::factory()->create(['due_at' => now()->addDay()]);
    $done = HelpRequest::factory()->create(['due_at' => now()->subDay(), 'status' => HelpRequestStatus::Resolved]);

    Livewire::test(ListHelpRequests::class)
        ->filterTable('overdue')
        ->assertCanSeeTableRecords([$overdue])
        ->assertCanNotSeeTableRecords([$future, $done]);
});

it('lets an admin change the priority, recomputing the due date from submission', function () {
    $this->actingAs(User::factory()->create());

    $this->travelTo(Carbon::parse('2026-09-09 10:00'));
    $request = HelpRequest::factory()->create();
    $this->travelTo(Carbon::parse('2026-11-09 10:00'));

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->callAction(TestAction::make('setPriority'), ['priority' => 'low'])
        ->assertHasNoActionErrors();

    $request->refresh();

    expect($request->priority)->toBe(HelpRequestPriority::Low)
        ->and($request->due_at->toDateTimeString())->toBe($request->created_at->copy()->addMonth()->toDateTimeString())
        ->and($request->isOverdue())->toBeTrue();
});

it('shows priority and due date on the request page', function () {
    $this->actingAs(User::factory()->create());

    $request = HelpRequest::factory()->create(['priority' => HelpRequestPriority::High, 'due_at' => now()->addDay()]);

    Livewire::test(ViewHelpRequest::class, ['record' => $request->getKey()])
        ->assertSee(['High', $request->due_at->format('d M Y, g:i A')]);
});

it('gives no due date to internal notes nobody prioritised', function () {
    $note = HelpRequest::factory()->create(['is_internal' => true]);

    expect($note->due_at)->toBeNull()
        ->and($note->isOverdue())->toBeFalse();
});

it('sorts requests without a due date after dated ones, either way', function () {
    $this->actingAs(User::factory()->create());

    $undated = HelpRequest::factory()->create();
    $undated->forceFill(['due_at' => null])->save();
    $soon = HelpRequest::factory()->create(['due_at' => now()->addDay()]);
    $later = HelpRequest::factory()->create(['due_at' => now()->addWeek()]);

    Livewire::test(ListHelpRequests::class)
        ->sortTable('due_at')
        ->assertCanSeeTableRecords([$soon, $later, $undated], inOrder: true)
        ->sortTable('due_at', 'desc')
        ->assertCanSeeTableRecords([$later, $soon, $undated], inOrder: true);
});

it('agrees with the overdue scope on which statuses are finished', function () {
    expect(HelpRequestStatus::finished())->toBe(
        array_values(array_filter(HelpRequestStatus::cases(), fn (HelpRequestStatus $status): bool => $status->isFinished())),
    );
});
