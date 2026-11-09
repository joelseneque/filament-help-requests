# Changelog

All notable changes to `joelseneque/filament-help-requests`. Versions and
dates match the releases on
[Packagist](https://packagist.org/packages/joelseneque/filament-help-requests).
Upgrade steps for each version are in the README under
[Upgrading](README.md#upgrading).

## 2.5.0 - 2026-11-09

### Changed

- GitHub issues set the organisation's **Priority** and **Target date**
  issue fields instead of a `priority: high|medium|low` label, both when the
  issue is created and when the priority changes. The app needs the
  **Issue fields: Read-only** organisation permission; without it, or on a
  repository owned by a personal account, issues get the body line only.
  `labelsUsing()` hooks no longer receive a priority label. **Test
  connection** says whether the fields were found. See
  [Priority and Target date](README.md#priority-and-target-date).

## 2.4.0 - 2026-11-09

### Added

- GitHub issues created from a request show its priority and due date in
  the body and get a `priority: high`, `priority: medium` or `priority: low`
  label. Requests without a due date get neither. The `labelsUsing()` hook
  receives the priority label with the defaults and can drop it; if GitHub
  rejects it, the issue is created with only the default labels. See
  [Labels per issue](README.md#labels-per-issue).
- Changing the priority of a request that has a GitHub issue posts a comment
  on the issue ("Priority changed from Medium to Low · due …") and swaps the
  priority label. If GitHub can't be reached, the new priority is kept and
  the admin sees a warning.

## 2.3.0 - 2026-11-09

### Added

- Help requests have a priority and a due date. Requesters pick High (due the
  same day), Medium (one week, the default) or Low (one month) in the widget.
  Admins get Priority and Due columns, a priority filter, an Overdue toggle
  and a "Set priority" action that recomputes the due date from when the
  request was sent. Needs the new migration
  `add_priority_to_help_requests_table`; existing requests become Medium with
  no due date. Parley's internal help-article notes get no due date either.
  See [Priority](README.md#priority).

## 2.2.0 - 2026-11-02

### Added

- `HelpRequests::labelsUsing()` lets the app choose the labels each GitHub
  issue is created with. The closure gets the request and the default
  labels; without one, issues get the default labels as before. If GitHub
  rejects the hook's labels, the issue is created with the defaults instead.
  See [Labels per issue](README.md#labels-per-issue).

## 2.1.1 - 2026-10-30

### Fixed

- The default issue label for new installs is `help request` (was
  `help-request`). Existing settings rows keep whatever is saved.

## 2.1.0 - 2026-10-30

### Changed

- New installs start with `help-request` under **Default labels** on the
  settings page, so GitHub issues raised from the app are easy to filter.
  Sites that already have a settings row keep their saved labels. Create the
  label in the repository before connecting.

## 2.0.0 - 2026-10-22

### Added

- Parley, a help chat in the widget, answered from your app's
  `resources/help` articles. Needs `PARLEY_ANTHROPIC_KEY` (or
  `ANTHROPIC_API_KEY`); without one the widget is as before.
- Migration `create_parley_tables`, and `is_internal` +
  `parley_conversation_id` on `help_requests`.
- Config section `parley`.

### Changed

- **Breaking:** the default categories are now Bug and Feature. Old requests
  keep their labels; pass `->categories([...])` to keep the old five.
- The admin resource is no longer scoped to a tenant, so it lists every
  request on a multi-tenant panel.

## 1.2.1 - 2026-10-20

### Fixed

- `->settingsCluster()` now lists the settings page in that cluster's
  navigation.
- The registering plugin is cleared even if registration throws.

## 1.2.0 - 2026-10-20

### Added

- Documents (PDF, Word, Excel, CSV, text) can be attached alongside
  screenshots. Config key `storage.accepted_file_types`.

### Changed

- **Breaking:** migration `add_attachments_to_help_requests_tables` moves
  screenshots into an `attachments` list and drops the `screenshot_path` /
  `screenshot_paths` columns. `HasScreenshots` is now `HasAttachments`, with
  new attachment helper methods.

## 1.1.0 - 2026-10-08

### Added

- Several screenshots per request or reply, with markers and notes, and a
  collapsible **My requests** list. Config key `storage.max_files`.
- Step-by-step upgrade guide in the README.

### Changed

- Livewire widget properties renamed: `screenshot` → `screenshots`,
  `replyScreenshot` → `replyScreenshots`.

## 1.0.0 - 2026-09-25

- First release: floating help widget, admin resource with replies and
  statuses, email and database notifications, and optional GitHub App issue
  sync.
