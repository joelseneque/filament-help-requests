# Help Request Priority Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
> On approval, copy this file to `docs/superpowers/plans/2026-11-09-help-request-priority.md`.

**Goal:** Requesters pick High / Medium / Low when raising a bug or feature, each priority sets a due date (same day / 1 week / 1 month), and admins can re-triage and see what's overdue.

**Architecture:** New `HelpRequestPriority` enum owns labels, colours and the due-date rule. Two new columns on `help_requests` (`priority`, `due_at`) via a new `add_*` migration stub. The model fills `due_at` on create, so the widget, Parley's internal requests and factories all get one. Admin list gets priority/due columns and filters; the view page gets a "Set priority" action that recomputes `due_at` from `created_at`.

**Tech Stack:** Laravel, Filament v5, Livewire, Pest + Orchestra Testbench (SQLite in memory).

**Spec:** the user's request in chat (no spec file): "different levels for bugs and features… High (same day), Medium (1 week), Low (1 month)". Decisions from Q&A: requester picks, admin can change; Medium is preselected; deadline drives a due date + overdue flag in admin only (no email subject, no GitHub label).

## Global Constraints

- Priorities, exact: `high` → "High", same day; `medium` → "Medium", 1 week; `low` → "Low", 1 month.
- Due date: High = end of the creation day (`endOfDay()`, app timezone); Medium = `addWeek()`; Low = `addMonth()` — all from the request's creation time, never from "now" when re-triaging.
- Default priority `medium`, everywhere (DB default, model `$attributes`, widget initial value).
- Never edit shipped migration stubs; new stub must be registered in `HelpRequestsServiceProvider::hasMigrations()` AND `tests/TestCase.php::defineDatabaseMigrations()`.
- Keep README in sync (widget section, admin, upgrade notes); Pint default preset; docblocks explain why.

## Review Focus

1. Existing rows after upgrading: priority becomes Medium, `due_at` stays null, never shown as overdue (no surprise wall of red). → test in Task 1.
2. Parley's internal requests (`RecordHelpArticleGap`, which calls `HelpRequest::create` without a priority) still get Medium + a due date. → test in Task 1.
3. Resolved/Closed requests past their due date are not overdue. → test in Task 1.
4. Admin changing priority on an old request recomputes from `created_at`, not now (Low on a 2-month-old request is already overdue). → test in Task 3.
5. A tampered priority value posted from the widget is rejected. → test in Task 2.

---

### Task 1: Priority enum, migration, model

**Files:**
- Create: `src/Enums/HelpRequestPriority.php`
- Create: `database/migrations/add_priority_to_help_requests_table.php.stub`
- Modify: `src/HelpRequestsServiceProvider.php` (`hasMigrations` list), `tests/TestCase.php:82` (migration list)
- Modify: `src/Models/HelpRequest.php` (fillable, casts, `$attributes`, `booted()`, `isOverdue()`, `scopeOverdue()`, `@property` docblock)
- Modify: `README.md` (Upgrading → Version notes: new unreleased entry naming the migration)
- Test: `tests/Feature/HelpRequestPriorityTest.php` (new)

**Interfaces:**
- Produces: `enum HelpRequestPriority: string implements HasColor, HasLabel { High='high'; Medium='medium'; Low='low' }` with `getLabel(): string`, `getColor(): string` (high `danger`, medium `warning`, low `gray`), `timeframe(): string` ("today", "within a week", "within a month"), `dueAt(CarbonInterface $from): Carbon`.
- Produces: `HelpRequest::$priority` (enum cast), `HelpRequest::$due_at` (`datetime`), `isOverdue(): bool`, `scopeOverdue(Builder $query): void`.

- [ ] **Step 1: Write the failing tests** in `HelpRequestPriorityTest.php` (freeze time with `$this->travelTo(Carbon::parse('2026-11-09 10:00'))`):
  - `it('works out the due date for each priority')` — `dueAt` from `2026-11-09 10:00`: High → `2026-11-09 23:59:59`, Medium → `2026-11-16 10:00`, Low → `2026-12-09 10:00`.
  - `it('defaults a new request to medium with a due date')` — `HelpRequest::create([...minimal fields, no priority])` → `priority === Medium`, `due_at == 2026-11-16 10:00`. (Covers Review Focus 2: same path Parley uses.)
  - `it('keeps an explicit due date')` — factory with `due_at` set is not overwritten.
  - `it('does not treat requests without a due date as overdue')` — `forceFill(['due_at' => null])->save()`; `isOverdue()` false, not in `HelpRequest::overdue()`. (Review Focus 1.)
  - `it('flags open requests past their due date as overdue')` — High created, `travel(1)->days()`; `isOverdue()` true, in `overdue()`.
  - `it('does not flag finished requests as overdue')` — same but status Resolved and Closed → false, not in scope. (Review Focus 3.)
- [ ] **Step 2: Run** `vendor/bin/pest --filter=HelpRequestPriorityTest` — expect FAIL (class/column missing).
- [ ] **Step 3: Implement.** Migration: `string('priority')->default('medium')->after('category')->index()`, `timestamp('due_at')->nullable()->after('priority')->index()`; `down()` drops both indexes and columns; docblock says existing rows get no `due_at` on purpose. Model: `$attributes = ['priority' => 'medium']`; `booted()` registers `creating` that sets `due_at ??= $this->priority->dueAt(now())`. `isOverdue()` = `due_at` not null, in the past, and `! status->isFinished()`; scope mirrors it with `whereNotIn('status', [Resolved, Closed])`. Register the stub in both lists.
- [ ] **Step 4: Run** `composer test` — expect all green.
- [ ] **Step 5: README** version note: "Unreleased — new migration `add_priority_to_help_requests_table` (step 2). Existing requests become Medium with no due date." Then commit: `feat: add priority and due date to help requests`.

### Task 2: Priority picker in the widget

**Files:**
- Modify: `src/Livewire/HelpRequestWidget.php` (property, rule, `submit()` create payload, reset list, computed `priorities()`)
- Modify: `resources/views/livewire/help-request-widget.blade.php` (radio group after the category group, ~line 290)
- Modify: `README.md` (## The widget → new `### Priority` subsection)
- Test: `tests/Feature/HelpRequestPriorityTest.php`

**Interfaces:**
- Consumes: `HelpRequestPriority` (Task 1).
- Produces: Livewire `public string $priority = 'medium'`; `priorities(): array<string, string>` (`value => "High — today"` etc., built from `getLabel()` + `timeframe()`).

- [ ] **Step 1: Failing tests:**
  - `it('preselects medium and offers all three priorities')` — `Livewire::test(HelpRequestWidget::class)->assertSet('priority', 'medium')->assertSee(['High', 'today', 'within a week', 'within a month'])`.
  - `it('stores the chosen priority and resets to medium')` — set `priority` `high`, comment, submit → no errors, `assertSet('priority', 'medium')`; sole request `priority === High`, `due_at` = end of today.
  - `it('rejects a priority that is not offered')` — set `priority` `urgent`, submit → `assertHasErrors(['priority'])`, no request created. (Review Focus 5.)
- [ ] **Step 2: Run** `vendor/bin/pest --filter=HelpRequestPriorityTest` — expect the three new tests FAIL.
- [ ] **Step 3: Implement.** Rule `['required', Rule::enum(HelpRequestPriority::class)]`; pass `'priority' => $this->priority` to `create`; add `'priority'` to the `reset(...)` call. Markup: copy the category radio group's structure and classes, `wire:model="priority"`, `name="help-request-priority"`, `aria-label="How urgent is this?"`, `wire:key="hr-priority-{{ $key }}"`, with an `@error('priority')` line. Always shown (not tied to categories).
- [ ] **Step 4: Run** `composer test` — all green.
- [ ] **Step 5: README** `### Priority`: three levels and their deadlines, Medium preselected, admins can change it. Commit: `feat: let requesters choose a priority`.

### Task 3: Admin list, view page and re-triage

**Files:**
- Modify: `src/Filament/Resources/HelpRequests/Tables/HelpRequestsTable.php` (columns + filters)
- Modify: `src/Filament/Resources/HelpRequests/Pages/ViewHelpRequest.php` (infolist entries after `status`; new header action after `updateStatus`)
- Modify: `README.md` (admin resource description: priority, due, overdue filter, Set priority)
- Test: `tests/Feature/HelpRequestPriorityTest.php`

**Interfaces:**
- Consumes: `HelpRequestPriority`, `HelpRequest::isOverdue()`, `HelpRequest::overdue()` (Task 1).
- Produces: table column `priority` (badge, enum provides label/colour), column `due_at` (label "Due", `dateTime('d M Y, g:i A')`, sortable, `placeholder('—')`, colour `danger` when `isOverdue()`); filters `SelectFilter::make('priority')->options(HelpRequestPriority::class)` and `Filter::make('overdue')->toggle()` using the scope; header action `setPriority` (Select `priority`, required, default current value).

- [ ] **Step 1: Failing tests:**
  - `it('shows and filters by priority in the admin list')` — high + low requests; `ListHelpRequests` `assertSee('High')`, `filterTable('priority', 'high')` sees only high.
  - `it('filters the admin list to overdue requests')` — one overdue open, one future, one overdue resolved; `filterTable('overdue')` sees only the first.
  - `it('lets an admin change the priority, recomputing the due date from submission')` — request created 2 months ago as Medium; `callAction(TestAction::make('setPriority'), ['priority' => 'low'])` → `assertHasNoActionErrors()`, `priority === Low`, `due_at == created_at->addMonth()`, `isOverdue()` true. (Review Focus 4.)
  - `it('shows priority and due date on the request page')` — `ViewHelpRequest` `assertSee(['High', <formatted due_at>])`.
- [ ] **Step 2: Run** `vendor/bin/pest --filter=HelpRequestPriorityTest` — expect new tests FAIL.
- [ ] **Step 3: Implement.** Infolist: `TextEntry::make('priority')->badge()` and `TextEntry::make('due_at')->label('Due')->dateTime(...)->color(overdue ? 'danger' : null)->placeholder('—')`. Action `setPriority`: label "Set priority", icon `heroicon-o-bolt`, colour gray; `action` updates `priority` and `due_at = $priority->dueAt($record->created_at)`, sends "Priority updated" success notification. No requester notification, no GitHub push. Keep the list's default sort as `created_at desc`.
- [ ] **Step 4: Run** `composer test` then `composer format` — all green, Pint clean.
- [ ] **Step 5: README** admin notes. Commit: `feat: show priority and overdue requests to admins`.

## Verification

- `composer test` passes; `composer format` leaves no diff.
- Manual smoke (optional, in a host app with the package path-linked): publish migrations, migrate, raise a High bug from the **?** widget, see it in the admin list with today's due time, change it to Low on the view page, toggle the Overdue filter.

## Out of scope (deliberately)

Priority in admin email subject/body, GitHub priority labels, priority shown in the requester's "My Requests" list, business-day/working-hours deadlines, reminders when something goes overdue.
