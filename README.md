# Help Requests for Filament

A drop-in help desk for Filament v5 panels.

- **Floating help button** on every panel page. Users pick what kind of
  feedback it is ("Looks broken", "Confusing", …), describe it, and can attach
  screenshots (up to 5) and a Loom video. Screenshots can be pasted or dropped
  in, then marked with numbered pins ("mark where it goes wrong"); the pins are
  drawn onto the image and each pin's note is added to the request text. The page
  they were on is captured automatically.
- **"My Requests" tab** in the same widget: a collapsible list of their requests
  (one thread open at a time) where they read replies and reply back.
- **Admin triage** — a resource to list, filter, reply (with screenshots),
  change status and delete requests.
- **Notifications** — email + in-app to admins on new requests and replies;
  email + in-app to the requester on replies and resolution.
- **Optional GitHub sync** via a GitHub App — raise requests as issues,
  mirror replies as issue comments, and let closing or reopening the issue
  update the request.

## Requirements

- PHP 8.2+, Laravel 11–13, Filament 5, Livewire 3 or 4
- A custom Filament theme (for the widget's styles — see [Styles](#styles))
- A user model with `email`, a `name` (or `first_name` / `last_name`) and the
  `Notifiable` trait
- A `notifications` table (`php artisan make:notifications-table` if you don't
  have one)
- A queue worker, if you use the GitHub integration

## Installation

```bash
composer require joelseneque/filament-help-requests
```

Publish and run the migrations, and publish the config:

```bash
php artisan vendor:publish --tag="help-requests-migrations"
php artisan migrate
php artisan vendor:publish --tag="help-requests-config"
```

Make sure screenshots can be served:

```bash
php artisan storage:link
```

### Register the plugin

```php
use Joelseneque\HelpRequests\HelpRequestsPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->databaseNotifications() // requesters see replies in-app
        ->plugin(
            HelpRequestsPlugin::make()
                ->navigationGroup('Support'),
        );
}
```

`->databaseNotifications()` is needed for the in-app notifications. Without
it, replies still arrive by email but users have no bell to see them in.

### Who can manage requests

Out of the box, a user can see the admin list and the settings page when
**either** `$user->isAdmin()` returns true **or** they have the `super_admin`
role (spatie/laravel-permission). If your app has neither, nobody will see
the admin pages until you say who can:

```php
HelpRequestsPlugin::make()
    ->authorizeUsing(fn (User $user): bool => $user->is_staff)
```

Everyone who can sign in to the panel can raise requests.

### Styles

The widget uses Tailwind utilities that Filament's own stylesheet does not
include. Import the plugin stylesheet in your panel's theme, after the
Filament import:

```css
@import '../../../../vendor/filament/filament/resources/css/theme.css';
@import '../../../../vendor/joelseneque/filament-help-requests/resources/css/plugin.css';
```

Then rebuild your assets (`npm run build`). Without this the help button
renders unstyled.

## The widget

### Page name

The widget shows **About {page}** above the form, taken from the browser tab
title with the panel's brand name removed ("Deals — record - Acme" becomes
"Deals — record"). It follows `wire:navigate` page changes and is saved as
the request's page, alongside the full URL.

### Feedback categories

Users can tag a request with one of these buttons (optional):

| Key | Label |
| --- | --- |
| `change` | Change this |
| `missing` | Something missing |
| `broken` | Looks broken |
| `confusing` | Confusing |
| `works_well` | This works well |

Replace them with your own list:

```php
HelpRequestsPlugin::make()
    ->categories([
        'idea' => 'I have an idea',
        'broken' => 'Looks broken',
        'Needs a fix', // no key: stored as 'needs_a_fix'
    ])
```

Pass `->categories([])` to hide the buttons. A closure is also accepted if
the list depends on something at runtime.

Categories are stored by key, so relabelling one keeps old requests
correct. A key you later remove still shows a readable label on old
requests. The category appears in the admin list (with a filter), on the
request page, in the admin email and in the GitHub issue title.

### Loom videos

Users can paste a Loom share link with their request. Admins get the video
embedded on the request page, with an **Open in Loom** link.

The **Record a Loom** link beside the field first tries to open the Loom
desktop app (`loomDesktop://`). If the page hasn't lost focus about a second
later, it assumes the app isn't installed and opens loom.com in a new tab.
Cmd/Ctrl-click always goes straight to the website. Because browsers can't
report whether an app is installed, this is a best guess:

- Chrome asks "Open Loom?" the first time; loom.com may also open behind that
  prompt until the user ticks "Always allow".
- Safari may show an "address is invalid" alert before falling back when the
  app isn't installed.

Turn the field off per panel:

```php
HelpRequestsPlugin::make()->videoLinks(false)
```

Other options live in `config/help-requests.php` under `video`:

```php
'video' => [
    'enabled' => true,                          // fallback when ->videoLinks() isn't called
    'record_url' => 'https://www.loom.com/',    // where "Record a Loom" goes
    'record_app_url' => 'loomDesktop://',       // null = always go to record_url
    'allowed_hosts' => ['loom.com'],            // [] = accept any https link
],
```

Links must be `https` and on an allowed host (subdomains included). Links
from other hosts are shown as a plain link rather than an embedded player.

### Linking to the widget

- Open a user's threads from your own code: dispatch the Livewire event
  `open-help-requests`.
- Link straight to a thread: any panel URL with `?help_request={id}`. This is
  how the requester's notifications link back, because the admin page would
  deny them.

## Configuration

`config/help-requests.php`:

| Key | Default | Purpose |
| --- | --- | --- |
| `user_model` | `App\Models\User` | The model that raises and answers requests. |
| `user_searchable_columns` | `['name', 'email']` | Columns searched by the admin list's "From" column. |
| `recipient` | `HELP_REQUEST_EMAIL` | Inbox emailed about new requests and replies. `null` = no email. |
| `admin_role` | `super_admin` | Role used by the default manage check and for in-app admin alerts. |
| `notifications.channels` | `['database', 'mail']` | Channels used for requester notifications. |
| `video.*` | see above | Loom link options. |
| `storage.*` | `public`, `help-requests`, 5120 KB, 5 files | Screenshot disk, directory, max size per file and max screenshots per request or reply. |
| `branding.name` / `branding.logo_url` | `APP_NAME` / none | Shown in emails and GitHub issue text. |
| `routes.*` | `webhooks/github`, `github/connect`, `github/callback` | Route paths and install-route middleware. |
| `github.*` | `GITHUB_APP_*` env vars | GitHub App credentials; `schedule_sync` toggles the hourly poll. |

Admins who get the in-app alert: the user whose email matches `recipient`,
otherwise everyone with `admin_role`.

## Plugin options

All options apply app-wide, including queued jobs and the webhook.

```php
HelpRequestsPlugin::make()
    // Navigation
    ->navigationGroup('Support')
    ->navigationSort(90)
    ->settingsCluster(SettingsCluster::class)   // put the settings page in a cluster
    ->settingsNavigationGroup('Settings')
    ->settingsNavigationSort(10)

    // Widget
    ->categories([...])                          // or [] to hide
    ->videoLinks(false)                          // hide the Loom field

    // Access
    ->authorizeUsing(fn (User $user) => $user->hasRole('support'))
    ->authorizeSettingsUsing(fn (User $user) => $user->hasRole('super_admin'))

    // Who gets the in-app "new request / new reply" alert
    ->adminRecipientsUsing(fn () => User::role('support')->get())

    // Take over requester notifications, e.g. to honour user preferences.
    // $type is HelpRequestNotifier::TYPE_REPLY or TYPE_RESOLVED.
    ->sendNotificationsUsing(function (User $user, Notification $notification, string $type, array $context) {
        $user->notify($notification->onlyVia(['database']));

        if ($user->wantsEmailFor($type)) {
            $user->notify($notification->onlyVia(['mail']));
        }
    })

    // Turn parts off in this panel
    ->widget(false)
    ->resource(false)
    ->settingsPage(false);
```

With several panels, register the plugin on each panel where it should
appear. `->resource(false)` / `->widget(false)` let you put the admin pages
in one panel and the help button in another.

## GitHub integration (optional)

Requests can be raised as issues in a GitHub repository, either
automatically or with a button on each request. Replies written in the app
are posted to the issue. Closing an issue marks the request **Resolved**,
closing it as *not planned* marks it **Closed**, and reopening it marks it
**Open**. Comments made on the issue stay in GitHub — they are not shown to
the requester.

### Create a GitHub App for each site

A GitHub App has **one** Setup URL and **one** Webhook URL, so create a
separate app for each site that uses this package. Sharing one app between
sites breaks the connect flow and sends every webhook to the first site.

In GitHub: **Settings → Developer settings → GitHub Apps → New GitHub App**

- **Setup URL:** `https://your-app.test/github/callback`
- **Webhook URL:** `https://your-app.test/webhooks/github`
- **Webhook secret:** any random string
- **Repository permissions → Issues:** Read and write
- **Subscribe to events:** Issues, Installation

Generate a private key, then add to `.env`:

```dotenv
GITHUB_APP_ID=
GITHUB_APP_SLUG=              # from the app's public URL: github.com/apps/{slug}
GITHUB_APP_PRIVATE_KEY=       # raw PEM, base64 of the PEM, or a path to the .pem file
GITHUB_APP_WEBHOOK_SECRET=
```

A path to the `.pem` must exist on every server the app runs on. Pasting the
base64 of the `.pem` avoids that.

### Connect

Open **Help Request Settings** in the panel, click **Connect to GitHub**,
choose which repositories the app can access, then pick the repository for
this site and save. The connection is stored in this site's database, so
each environment (local, staging, production) connects separately.

### Queue and schedule

Automatic issue creation runs on the queue, so a worker must be running. An
hourly `help-requests:sync-github` is scheduled automatically to catch any
missed webhooks; your scheduler (`php artisan schedule:run`) must be running.
Turn it off with `github.schedule_sync => false`.

```bash
php artisan help-requests:github-status   # checks env, settings, queue and a live connection
php artisan help-requests:sync-github     # poll linked issues now; --all includes finished ones
```

## Upgrading

Follow these steps in each app that uses the package.

1. **Update the package.**

   ```bash
   composer update joelseneque/filament-help-requests
   ```

   For a new major version, change the constraint in `composer.json` first
   (e.g. `"^2.0"`).

2. **Publish and run new migrations.** Files you already published are
   skipped, so only new ones are copied:

   ```bash
   php artisan vendor:publish --tag="help-requests-migrations"
   php artisan migrate
   ```

   Commit the newly published migration files with the upgrade.

3. **Check your published config**, if you have one. Compare it with the
   package's `config/help-requests.php` and copy over new keys you want to
   change. Laravel only merges top-level sections, so a new key inside a
   section you published (e.g. `storage.max_files`) is missing from your file
   and uses its built-in default until you add it.

4. **Check overridden views**, if you published any to
   `resources/views/vendor/help-requests`. They will not pick up the
   package's changes; diff them against the package's `resources/views`.

5. **Rebuild the panel theme**, so Tailwind generates classes used by new
   widget markup:

   ```bash
   npm run build
   ```

6. **Clear caches:**

   ```bash
   php artisan optimize:clear
   ```

   Restart queue workers (`php artisan queue:restart`) if you use the GitHub
   integration.

7. **Smoke test:** open a panel page, send a help request from the **?**
   button, reply to it from the admin resource and from **My Requests**.

### Version notes

**1.1.0**

- New migration `add_screenshot_paths_to_help_requests_tables` (step 2).
- New config key `storage.max_files` (default `5`) — add it to a published
  config's `storage` section to change it (step 3).
- New widget markup: rebuild the theme (step 5).
- Livewire widget properties renamed: `screenshot` → `screenshots`,
  `replyScreenshot` → `replyScreenshots`. Only matters if you extended or
  tested the widget.

### Trying an unreleased version in your app

To test a local checkout of this package in an app before it is released,
point the app at the checkout with a Composer path repository:

```json
"repositories": [
    { "type": "path", "url": "../filament-help-requests", "options": { "symlink": true } }
]
```

```bash
composer require "joelseneque/filament-help-requests:@dev"
```

Then follow steps 2–7. When you are done, remove the repository entry and
run `composer require "joelseneque/filament-help-requests:^1.0"` again, so the
app's `composer.json` and `composer.lock` don't point at your local copy.

## Testing

```bash
composer install
composer test
```

## License

MIT. See [LICENSE](LICENSE).
