# GitHub Issue Fields Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Set GitHub's built-in **Priority** and **Target date** issue fields from a help request, instead of the `priority: *` labels added in 2.4.0.

**Architecture:** `GitHubIssueService` looks up the organisation's issue fields (`GET /orgs/{owner}/issue-fields`, cached), builds `issue_field_values` for Priority (option name = `HelpRequestPriority::getLabel()`) and Target date (`due_at` as `Y-m-d`), and sends them in the create-issue request. Re-triage (`pushPriority`) updates them with `POST /repos/{repo}/issues/{n}/issue-field-values` instead of swapping labels. The priority label code from 2.4.0 is removed.

**Tech Stack:** Laravel `Http` (faked in tests), `Cache`, Pest + Testbench.

**Spec:** user's chat request ("use Priority not a label") + Q&A: set **Priority and Target date**; when the fields can't be used (personal-account repo, missing permission, field renamed/deleted) the issue gets the body line only — no label fallback. GitHub docs: [issue field values](https://docs.github.com/en/rest/issues/issue-field-values), [org issue fields](https://docs.github.com/en/rest/orgs/issue-fields), [create issue](https://docs.github.com/en/rest/issues/issues) (`issue_field_values` body key; silently dropped on user-owned repos).

## Global Constraints

- Field lookup: `GET /orgs/{owner}/issue-fields` where `{owner}` is the part of the repository before `/`. Match by `name`, case-insensitive: `Priority` (`data_type` `single_select`) and `Target date` (`data_type` `date`).
- Values: `{"field_id": <id>, "value": "High"}` — single-select by **option name**, only if that option exists on the field; date as `Y-m-d`. Requests with no `due_at` send no field values.
- Cache the org's field list for 1 hour under `github-issue-fields-{owner}`; never cache an empty/failed lookup (same rule as `availableRepositories()`).
- A failed lookup (404 personal account, 403 missing permission, network) logs a warning and creates the issue without field values. Field problems never cost the issue.
- 422 on create: retry once with the saved default labels and **no** `issue_field_values`.
- Remove the 2.4.0 priority label: `HelpRequestPriority::githubLabel()`, the label added to `$defaults` for `labelsUsing()`, and the label swap in `pushPriority()`. The body line `**Priority:** High · due …` stays.
- Re-triage keeps the comment; the field update after it is best-effort (warning log, still `ok`).
- README (GitHub integration, Labels per issue, new App permission), CHANGELOG `## Unreleased` (`### Changed`).

## Review Focus

1. Repo owned by a personal account (lookup 404) → issue created, no field values, no extra failure. → Task 1.
2. App not granted the issue-fields permission (lookup 403) → same as 1, warning logged; connection test says so. → Task 1.
3. Priority field exists but lacks the option (e.g. renamed "High" → "P1") → Priority skipped, Target date still sent. → Task 1.
4. Lookup cached: two issues in a row → one `GET /orgs/acme/issue-fields`. → Task 1.
5. Re-triage when the field update fails → comment posted, result ok, warning logged. → Task 2.

---

### Task 1: Field values on new issues; drop the priority label

**Files:**
- Modify: `src/Services/GitHubIssueService.php` (`createIssue()`, new `issueFieldValues()`, `testConnection()`)
- Modify: `src/Enums/HelpRequestPriority.php` (remove `githubLabel()` — re-add nothing)
- Modify: `README.md`, `CHANGELOG.md`
- Test: `tests/Feature/GitHubIssueServiceTest.php`; add `fakeIssueFields()` helper to `tests/Pest.php`

**Interfaces:**
- Produces: `GitHubIssueService::issueFieldValues(HelpRequest $helpRequest): list<array{field_id: int, value: string}>` (public; Task 2 uses it).
- Test helper: `fakeIssueFields(): array` returns the `/orgs/acme/issue-fields` response body: Priority (id 11, single_select, options Urgent/High/Medium/Low) and Target date (id 12, date).

- [ ] **Step 1: Tests** (`GitHubIssueServiceTest.php`):
  - `it('sets the priority and target date fields on the new issue')` — fake `api.github.com/orgs/acme/issue-fields` → `fakeIssueFields()` and issues endpoint 201; High request; create payload `issue_field_values === [['field_id' => 11, 'value' => 'High'], ['field_id' => 12, 'value' => $r->due_at->toDateString()]]`.
  - `it('creates the issue without field values when the organisation has none')` — fields endpoint 404; result ok; payload has no `issue_field_values` key. (RF 1)
  - `it('creates the issue without field values when the app cannot read the fields')` — 403; ok; no key; `Log::shouldHaveReceived('warning')`. (RF 2)
  - `it('skips the priority field when it has no matching option')` — Priority options `['P1','P2']`; payload has only the Target date entry. (RF 3)
  - `it('looks the fields up once per organisation')` — two creates; `Http::assertSentCount` shows one request to `/orgs/acme/issue-fields`. (RF 4)
  - `it('sends no field values for requests without a due date')` — `is_internal` request; no key, and no lookup request sent.
  - `it('retries without field values when github rejects the issue')` — 422 then 201; second payload lacks `issue_field_values` and has `labels === ['help request']`.
  - Revert the 2.4.0 label expectations back to `['help request']` / hook defaults `['help request']`; delete 'labels the issue with its priority' and 'creates the issue with the default labels when github rejects the priority label'; restore 'surfaces a github error…' to `assertSentCount(2)` only if the fields lookup counts (it does: lookup + create, no retry when nothing changed) — assert the create was sent once.
  - Every existing create test now hits the lookup: fake `api.github.com/orgs/*` → 404 in `beforeEach` unless a test overrides it.
- [ ] **Step 2: Run** `vendor/bin/pest --filter=GitHubIssueServiceTest` — new tests FAIL.
- [ ] **Step 3: Implement.** `issueFieldValues()`: return `[]` when `due_at` is null; otherwise fetch (or read cache) the org's fields, then build the two entries per Global Constraints. In `createIssue()`: labels back to `HelpRequests::issueLabels($helpRequest, $defaults)`; add `'issue_field_values' => $fieldValues ?: null` to the payload (the `array_filter` drops it when empty); retry on 422 when `$labels !== $defaults || $fieldValues !== []`, with `labels => $defaults` and no field values. In `testConnection()`, after the issues-permission check, append to the ok message: ` Priority and Target date fields: found.` or ` Priority fields unavailable — grant the app read access to the organisation's issue fields, or ignore this for a personal-account repository.` (lookup with `fresh` = bypass cache).
- [ ] **Step 4: Run** `composer test`, `composer format` — green.
- [ ] **Step 5: Docs + commit.** README: issues set Priority and Target date fields (org-owned repos, default fields); App permission to add: **Organization permissions → Issue fields: Read-only**; each installation must accept the new permission; priority labels are gone (delete them from repos if wanted). Labels per issue: remove the priority-label paragraph and the "(including the priority label…)" aside. CHANGELOG Unreleased `### Changed`. Commit `feat: set GitHub's Priority and Target date fields instead of a label`.

### Task 2: Re-triage updates the fields

**Files:**
- Modify: `src/Services/GitHubIssueService.php` (`pushPriority()`)
- Modify: `README.md`, `CHANGELOG.md`
- Test: `tests/Feature/GitHubIssueServiceTest.php`

**Interfaces:**
- Consumes: `issueFieldValues()` (Task 1).

- [ ] **Step 1: Tests:** rewrite 'comments on the issue and swaps the label…' as `it('comments on the issue and updates its fields when the priority changes')` — comment POST as before; then `POST …/issues/42/issue-field-values` with `issue_field_values === [['field_id' => 11, 'value' => 'Low'], ['field_id' => 12, 'value' => '2026-12-09']]`; no `/labels` requests. Replace 'still adds the new priority label when the old one is already gone' with `it('keeps the comment when the field update fails')` — field-values endpoint 403 → ok true, warning logged. (RF 5) Keep 'reports when github rejects the priority comment' asserting no `/issue-field-values` request.
- [ ] **Step 2: Run** `vendor/bin/pest --filter=GitHubIssueServiceTest` — FAIL.
- [ ] **Step 3: Implement:** after a successful comment, if `issueFieldValues()` is non-empty POST them to `{issue}/issue-field-values`; on failure `Log::warning('GitHub issue field update failed', …)`. Delete the label DELETE/POST.
- [ ] **Step 4: Run** `composer test`, `composer format` — green.
- [ ] **Step 5: Docs + commit.** README: re-triage comments and updates Priority/Target date. Commit `feat: update GitHub's issue fields when a request is re-triaged`.

## Verification

- `composer test` green, Pint clean.
- After updating the GitHub App permission and accepting it on the installation: **Test connection** on the settings page reports the fields as found; a new High request's issue shows Priority = High and Target date.

## Out of scope

Field names other than "Priority"/"Target date" (no config), mapping to "Urgent", Effort/Start date, pulling field changes back from GitHub, a label fallback.
