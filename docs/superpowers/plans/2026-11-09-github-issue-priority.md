# GitHub Issue Priority Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** GitHub issues created from help requests show the request's priority and due date, and re-triaging a linked request tells the issue.

**Architecture:** Issue creation (`GitHubIssueService::createIssue`) adds a `**Priority:**` line to the body and a `priority: <level>` label to the labels it hands the existing `labelsUsing()` hook. The existing 422 retry falls back to the saved default labels, so a repo without the priority labels still gets its issue. A new `GitHubIssueService::pushPriority()` posts a comment and swaps the label; the admin "Set priority" action calls it.

**Tech Stack:** Laravel, Filament v5, Laravel `Http` (faked in tests), Pest + Orchestra Testbench.

**Spec:** the user's chat request: "add Priority / due date to Github Issues when brought across". Decisions from Q&A: show it in the issue body **and** as a priority label (issue still created if the repo rejects the label); when an admin changes the priority on a request that already has an issue, **post a comment** (and swap the label), never rewrite the body.

## Global Constraints

- Label names, exact: `priority: high`, `priority: medium`, `priority: low` (from `HelpRequestPriority::githubLabel()`).
- Body line, exact form: `**Priority:** High · due 09 Nov 2026, 11:59 PM` — label from `getLabel()`, date `d M Y, g:i A` (same format as `**Submitted at:**`), placed right after the `**Type:**` line.
- Requests with no `due_at` (Parley's internal notes, requests from before 2.3.0) get **no** priority line and **no** priority label — at creation and on re-triage nobody chose a priority for them.
- Comment text, exact: `Priority changed from Medium to Low in {appName} · due 09 Dec 2026, 10:00 AM` (`HelpRequests::appName()`).
- A GitHub failure never blocks or undoes the local change; the admin sees a "GitHub was not updated" warning, as `applyStatus()` does today.
- Sync stays one-way for comments (CLAUDE.md); GitHub label changes are not pulled back.
- Keep README (GitHub integration / Labels per issue) and CHANGELOG `## Unreleased` in sync; Pint default preset; docblocks explain why.

## Review Focus

1. Repo without the `priority: *` labels → GitHub 422 → issue created on the retry with only the saved default labels. → test in Task 1.
2. Requests without a due date (internal Parley notes) → no priority line, no priority label. → test in Task 1.
3. GitHub unreachable while re-triaging → priority still saved locally, admin gets a warning. → test in Task 2.
4. Old priority label already removed on GitHub (DELETE returns 404) → new label still added, result ok. → test in Task 2.
5. Re-triage to the same priority, or on a request with no issue → no GitHub calls. → test in Task 2.

---

### Task 1: Priority on newly created issues

**Files:**
- Modify: `src/Enums/HelpRequestPriority.php` (add `githubLabel()`)
- Modify: `src/Services/GitHubIssueService.php` (`createIssue()` labels, `issueBody()`)
- Modify: `README.md` (`### Labels per issue`), `CHANGELOG.md` (`## Unreleased` → `### Added`)
- Test: `tests/Feature/GitHubIssueServiceTest.php`

**Interfaces:**
- Produces: `HelpRequestPriority::githubLabel(): string` → `'priority: '.$this->value`.
- Behaviour change for the hook: `labelsUsing()` closures now receive the saved defaults **plus** the priority label (when the request has a due date) as `$defaults`. The 422 retry still falls back to the saved defaults only.

- [ ] **Step 1: Write the failing tests** (in `GitHubIssueServiceTest.php`; requests from the factory are Medium with a due date):
  - `it('adds the priority and due date to the issue body')` — `HelpRequest::factory()->create(['priority' => HelpRequestPriority::High])` with time frozen at `2026-11-09 10:00` (`$this->travelTo`); assert the sent body contains `**Priority:** High · due 09 Nov 2026, 11:59 PM`.
  - `it('labels the issue with its priority')` — default factory request; `Http::assertSent(fn ($r) => $r['labels'] === ['help request', 'priority: medium'])`.
  - `it('leaves the priority off issues for requests without a due date')` — `create(['is_internal' => true])`; labels `=== ['help request']` and body does not contain `**Priority:**`. (Review Focus 2.)
  - `it('creates the issue with the default labels when github rejects the priority label')` — `Http::fakeSequence` 422 then 201, no hook; result ok, issue number linked, two requests sent, the second with `labels === ['help request']`. (Review Focus 1.)
  - Update existing expectations that a priority label now changes: 'creates a github issue and links it…' (`['help request', 'priority: medium']`), 'passes the request and the default labels to the label hook' (`[$id, ['help request', 'priority: medium']]`), 'still creates the issue with the default labels when the label hook throws' (`['help request', 'priority: medium']`). Leave hook-output tests (trims, returns none, adds 'triaged' → expect `['help request', 'priority: medium', 'triaged']`) asserting what the hook returned.
- [ ] **Step 2: Run** `vendor/bin/pest --filter=GitHubIssueServiceTest` — the new tests FAIL.
- [ ] **Step 3: Implement.** `githubLabel()` on the enum. In `createIssue()`: keep `$defaults` = saved labels; pass `array_values(array_unique([...$defaults, ...($helpRequest->due_at ? [$helpRequest->priority->githubLabel()] : [])]))` to `HelpRequests::issueLabels()`; leave the retry condition (`$labels !== $defaults`) and retry payload (`$defaults`) as they are. In `issueBody()`, after the Type line, add the Priority line only when `due_at` is set.
- [ ] **Step 4: Run** `composer test` — all green; `composer format`.
- [ ] **Step 5: Docs + commit.** README `### Labels per issue`: new issues also get `priority: high|medium|low` (not for requests without a due date); the hook receives it in `$defaults` and can drop it; create those three labels in the repo first, otherwise the issue is created with only the default labels. Mention the body line in the GitHub integration section. CHANGELOG `## Unreleased` / `### Added` entry. Commit: `feat: show priority and due date on new GitHub issues`.

### Task 2: Tell the issue when an admin re-triages

**Files:**
- Modify: `src/Services/GitHubIssueService.php` (new `pushPriority()`)
- Modify: `src/Filament/Resources/HelpRequests/Pages/ViewHelpRequest.php` (`setPriority` action, ~line 258)
- Modify: `README.md`, `CHANGELOG.md` (same entries as Task 1)
- Test: `tests/Feature/GitHubIssueServiceTest.php`, `tests/Feature/HelpRequestPriorityTest.php`

**Interfaces:**
- Consumes: `HelpRequestPriority::githubLabel()` (Task 1).
- Produces: `GitHubIssueService::pushPriority(HelpRequest $helpRequest, HelpRequestPriority $previous): array{ok: bool, message: string}`.

- [ ] **Step 1: Failing tests:**
  - In `GitHubIssueServiceTest.php`, `it('comments on the issue and swaps the label when the priority changes')` — linked request (`github_issue_number` 42, repo `acme/app`), now Low with `due_at` `2026-12-09 10:00`; `pushPriority($r, HelpRequestPriority::Medium)` → ok; sent: POST `…/issues/42/comments` with body `Priority changed from Medium to Low in {HelpRequests::appName()} · due 09 Dec 2026, 10:00 AM`; DELETE `…/issues/42/labels/priority%3A%20medium`; POST `…/issues/42/labels` with `labels === ['priority: low']`.
  - `it('still adds the new priority label when the old one is already gone')` — DELETE faked 404, others 200/201; result ok, the POST labels request was sent. (Review Focus 4.)
  - `it('reports when github rejects the priority comment')` — comments endpoint 500; `ok === false`, message starts `GitHub returned 500`, no label requests sent.
  - `it('does nothing on github when the priority is unchanged or there is no issue')` — `pushPriority($linkedMedium, Medium)` and `pushPriority($unlinked, High)` → `Http::assertNothingSent()`. (Review Focus 5.)
  - In `HelpRequestPriorityTest.php`, `it('tells the linked github issue when an admin changes the priority')` — `makeGitHubSettings()`, linked request, `callAction(TestAction::make('setPriority'), ['priority' => 'low'])` → a POST to `…/comments` was sent.
  - `it('keeps the new priority when github cannot be reached')` — `Http::fake(fn () => throw new ConnectionException('down'))`; action succeeds, `priority === Low` after refresh, `assertNotified('GitHub was not updated')`. (Review Focus 3.)
- [ ] **Step 2: Run** `vendor/bin/pest --filter="GitHubIssueServiceTest|HelpRequestPriorityTest"` — new tests FAIL.
- [ ] **Step 3: Implement `pushPriority()`** next to `pushStatus()`, same shape (settings/issue guard returning `['ok' => false, 'message' => 'Nothing to push.']`; `$previous === $helpRequest->priority` or `due_at` null → `['ok' => true, 'message' => 'GitHub is already up to date.']` with no calls; try/catch + `Log::error` like `pushStatus`). Comment first; on success, label swap is best-effort: DELETE the old label (`rawurlencode`), ignore 404, then POST the new one; a failed label call logs a warning but keeps `ok => true`. Update `github_synced_at`. In the `setPriority` action: capture `$previous` before `update()`; if `$record->hasGithubIssue()`, call `pushPriority()` and on `! ok` send the same warning notification `applyStatus()` sends (title `GitHub was not updated`, body = message) after the success notification.
- [ ] **Step 4: Run** `composer test` and `composer format` — all green, Pint clean.
- [ ] **Step 5: Docs + commit.** README: changing the priority on a linked request comments on the issue and swaps the priority label; the body is left as it was. CHANGELOG line added to the same Unreleased entry. Commit: `feat: tell the GitHub issue when a request is re-triaged`.

## Verification

- `composer test` passes; `composer format` leaves no diff.
- Optional manual check on a connected test repo: raise a High request, create the issue, see the Priority line and `priority: high` label; change it to Low and see the comment and label swap.

## Out of scope

Pulling priority changes back from GitHub labels, setting GitHub milestones/project fields from the due date, rewriting the issue body on re-triage, a release/tag (separate step after merge).
