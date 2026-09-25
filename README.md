# Help Requests

A drop-in help desk for Filament v5 panels:

- **Floating help widget** on every panel page — users raise a request (the page
  URL and title are captured automatically), attach a screenshot, and follow up
  on their own threads in a "My Requests" tab.
- **Admin triage resource** — list, filter, reply (with screenshots), change
  status, delete.
- **Notifications** — email + in-app to admins on new requests and replies;
  email + in-app to the requester on replies and resolution. Delivery can be
  handed to your own preference system.
- **Optional GitHub sync** via a GitHub App — raise requests as issues
  (manually or automatically), mirror replies as issue comments, and let
  closing/reopening the issue update the request (webhook + hourly poll).

## Installation

```bash
composer require joelseneque/help-requests
```

For local development against this folder, add a path repository to the
app's `composer.json` first:

```json
"repositories": [
    { "type": "path", "url": "../filament-packages/help-requests" }
]
```

Publish and run the migrations, and optionally the config:

```bash
php artisan vendor:publish --tag="help-requests-migrations"
php artisan migrate
php artisan vendor:publish --tag="help-requests-config"
```

Register the plugin on your panel:

```php
use Joelseneque\HelpRequests\HelpRequestsPlugin;

$panel->plugin(
    HelpRequestsPlugin::make()
        ->navigationGroup('Support')
        ->navigationSort(90)
        ->settingsCluster(SystemSettingsCluster::class) // optional
);
```

### Styles

The widget uses Tailwind utilities Filament does not ship, so add the plugin
stylesheet to your panel's custom theme, after the Filament import:

```css
@import '../../../../vendor/joelseneque/help-requests/resources/css/plugin.css';
```

Then rebuild your assets (`npm run build`).

### Screenshots

Screenshots go to the `public` disk under `help-requests/` by default. Make
sure `php artisan storage:link` has been run.

## Configuration

| Key | Default | Purpose |
| --- | --- | --- |
| `user_model` | `App\Models\User` | The model that raises and answers requests. Needs `email` and either `name` or `first_name`/`last_name`. |
| `user_searchable_columns` | `['name', 'email']` | Columns searched by the "From" column. |
| `recipient` | `HELP_REQUEST_EMAIL` | Admin inbox for new requests and replies. `null` = no email. |
| `admin_role` | `super_admin` | Fallback admin check / recipient role (spatie/laravel-permission). |
| `notifications.channels` | `['database', 'mail']` | Channels used for requester notifications. |
| `storage.*` | `public`, `help-requests`, 5120 KB | Screenshot disk, directory and max size. |
| `branding.name` / `branding.logo_url` | `APP_NAME` / none | Shown in emails and GitHub issue text. |
| `routes.*` | `webhooks/github`, `github/connect`, `github/callback` | Route paths. |
| `github.*` | `GITHUB_APP_*` env vars | GitHub App credentials; `schedule_sync` toggles the hourly poll. |

## Customising behaviour

All hooks are available on the plugin (they apply app-wide, including queued
jobs and the webhook):

```php
HelpRequestsPlugin::make()
    // Who can see and answer requests. Default: $user->isAdmin(), then admin_role.
    ->authorizeUsing(fn (User $user) => $user->hasRole('support'))

    // Who can open the GitHub settings page. Default: same as above.
    ->authorizeSettingsUsing(fn (User $user) => $user->hasRole(['super_admin', 'Management']))

    // Who gets the in-app "new request / new reply" notification.
    // Default: the user whose email matches `recipient`, else everyone with admin_role.
    ->adminRecipientsUsing(fn () => User::role('support')->get())

    // Take over requester notifications, e.g. to honour per-user preferences.
    // $type is HelpRequestNotifier::TYPE_REPLY or TYPE_RESOLVED.
    ->sendNotificationsUsing(function (User $user, Notification $notification, string $type, array $context) {
        $user->notify($notification->onlyVia(['database']));

        if ($user->wantsEmailFor($type)) {
            $user->notify($notification->onlyVia(['mail']));
        }
    })

    // Turn parts off per panel.
    ->widget(false)
    ->resource(false)
    ->settingsPage(false);
```

Other entry points:

- Open the widget on a user's threads from anywhere: dispatch the Livewire
  event `open-help-requests`.
- Link straight to a thread: any panel URL with `?help_request={id}`.

## GitHub integration (optional)

1. Create a GitHub App with **Issues: Read & write**, subscribed to the
   **Issues** and **Installation** events.
   - Webhook URL: `https://your-app.test/webhooks/github`
   - Setup URL: `https://your-app.test/github/callback`
2. Add to `.env`:
   ```dotenv
   GITHUB_APP_ID=
   GITHUB_APP_SLUG=
   GITHUB_APP_PRIVATE_KEY=   # raw PEM, base64 of the PEM, or a path to the .pem
   GITHUB_APP_WEBHOOK_SECRET=
   ```
3. Open **Help Request Settings** in the panel, click **Connect to GitHub**,
   choose a repository and save.

Commands:

```bash
php artisan help-requests:github-status   # diagnose env, settings, queue and live connection
php artisan help-requests:sync-github     # poll linked issues (scheduled hourly); --all includes finished ones
```

Issue creation runs on the queue, so a worker must be running.

## Migrating Squared onto the package

Squared already has `help_requests` and `help_request_replies` with the same
columns, so **do not** run the published `create_help_requests_tables`
migration there — delete it after publishing and keep only
`create_help_request_settings_table`. Then:

1. Copy the GitHub columns from `company_settings` into the new
   `help_request_settings` row (same column names), in a data migration.
2. The user model already defaults to `App\Models\User`;
   set `user_searchable_columns` to `['first_name', 'last_name', 'email']`.
3. Register the plugin with `->settingsCluster(SystemSettingsCluster::class)`,
   `->authorizeSettingsUsing(...)` for `super_admin|Management`, and
   `->sendNotificationsUsing(...)` calling `NotificationPreferenceService` with
   the matching `NotificationCategory`.
4. Set `branding.logo_url` to the Squared logo.
5. Remove the app's own help request classes, views, routes
   (`webhooks.github`, `github.connect`, `github.callback`), the render hook
   and the scheduled `help-requests:sync-github` entry — the package registers
   its own. Route names change to `help-requests.webhook`,
   `help-requests.github.connect` and `help-requests.github.callback`
   (paths stay the same, so the GitHub App needs no changes).

## Testing

```bash
composer test
```
