<?php

use Illuminate\Support\Carbon;
use Joelseneque\HelpRequests\Enums\HelpRequestPriority;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
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
