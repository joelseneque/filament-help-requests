# CLAUDE.md

Laravel package `joelseneque/filament-help-requests` — Filament v5 help desk plugin
(floating widget, admin resource, notifications, optional GitHub App issue sync).
User-facing docs live in README.md; keep it in sync when behaviour or options change.

## Commands
- `composer install` — no composer.lock is committed (it's gitignored)
- `composer test` — Pest via Orchestra Testbench, in-memory SQLite
- `vendor/bin/pest --filter=<name>` — single test
- `composer format` — Pint

## Layout
- `src/HelpRequestsServiceProvider.php` — spatie package-tools: config, views (`help-requests::`),
  routes, migrations, commands; registers Livewire `help-requests-widget`; schedules hourly
  `help-requests:sync-github` when `github.schedule_sync` is on.
- `src/HelpRequestsPlugin.php` — Filament plugin. Fluent options; panel-level toggles
  (widget/resource/settingsPage, navigation) live here. Widget injected via `PanelsRenderHook::BODY_END`.
- `src/HelpRequests.php` — **static app-wide hooks** (authorization, admin recipients,
  notification delivery, categories, video toggle, panel id, URL helpers). Static on purpose:
  jobs, webhook and console commands run outside any panel. Plugin setters write into it.
- `src/Livewire/HelpRequestWidget.php` — submit request / "My Requests" tab / user replies.
- `src/Filament/` — `HelpRequestResource` (list + view/reply page) and `HelpRequestSettings`
  page (GitHub connection).
- `src/Support/HelpRequestNotifier.php` — all notification fan-out (admin mail + DB, requester notifications).
- `src/Services/GitHubAppTokenService.php` — app JWT, installation token (cached on settings row, cache-locked refresh).
- `src/Services/GitHubIssueService.php` — create issue, post comment, push/pull status.
- `src/Http/Controllers/` — HMAC-verified webhook (outside `web` group, no CSRF) and GitHub App install flow.
- `src/Models/HelpRequestSetting.php` — singleton row (`current()`), holds per-database GitHub connection; token is `encrypted` cast.
- `database/migrations/*.php.stub` — published by host app.

## Conventions / gotchas
- **New migration**: add the stub, register it in `HelpRequestsServiceProvider::hasMigrations()`
  AND in the list in `tests/TestCase.php::defineDatabaseMigrations()`. Never edit shipped stubs —
  hosts skip already-published files, so add an `add_*` migration instead.
- **New static state in `HelpRequests`**: reset it in `HelpRequests::flush()` (called in TestCase tearDown).
- Requester links go through `HelpRequests::urlFor()` (panel home + `?help_request={id}`), never the
  admin URL — requesters would get 403. Admin links use `adminUrl()`, which has a fallback for no-panel contexts.
- Requester notifications must go through `HelpRequests::notify()` so `sendNotificationsUsing` can take
  over; notifications use `HasChannelPreference` to support `onlyVia()`.
- Categories are stored by key; `categoryLabel()` must keep working for removed keys.
- Sync is one-way for comments: app replies are posted to GitHub; GitHub `issue_comment` webhooks are
  ignored (issue comments stay internal). Issue close/reopen/delete does update the request.
- GitHub calls use Laravel `Http` — tests fake it with `Http::fake()`; helpers `makeGitHubSettings()`,
  `githubPayload()`, `postGithubWebhook()` are in `tests/Pest.php`.
- Test fixtures: `tests/Fixtures/{User,UserFactory,AdminPanelProvider}.php`.
- Code style: docblocks explain *why*; typed properties/returns; Pint default preset.
