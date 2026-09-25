<?php

namespace Joelseneque\HelpRequests;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\HelpRequestResource;
use Joelseneque\HelpRequests\Models\HelpRequest;

/**
 * App-wide hooks for the help desk.
 *
 * These are static rather than living on the panel plugin because they are
 * needed outside any panel — in queued jobs, the GitHub webhook and console
 * commands. {@see HelpRequestsPlugin} writes into them when the panel boots.
 */
class HelpRequests
{
    protected static ?Closure $authorizeUsing = null;

    protected static ?Closure $authorizeSettingsUsing = null;

    protected static ?Closure $adminRecipientsUsing = null;

    protected static ?Closure $sendNotificationsUsing = null;

    protected static ?string $panelId = null;

    /**
     * @var array<string, string>|Closure(): array<string, string>|null
     */
    protected static array|Closure|null $categories = null;

    protected static ?bool $videoLinksEnabled = null;

    /**
     * @param  Closure(Authenticatable $user): bool|null  $callback
     */
    public static function authorizeUsing(?Closure $callback): void
    {
        static::$authorizeUsing = $callback;
    }

    /**
     * @param  Closure(Authenticatable $user): bool|null  $callback
     */
    public static function authorizeSettingsUsing(?Closure $callback): void
    {
        static::$authorizeSettingsUsing = $callback;
    }

    /**
     * @param  Closure(): iterable<Authenticatable>|null  $callback
     */
    public static function resolveAdminRecipientsUsing(?Closure $callback): void
    {
        static::$adminRecipientsUsing = $callback;
    }

    /**
     * Take over delivery of the requester-facing notifications, e.g. to honour
     * per-user preferences or buffer them into a digest.
     *
     * @param  Closure(Authenticatable $user, Notification $notification, string $type, array<string, mixed> $context): void|null  $callback
     */
    public static function sendNotificationsUsing(?Closure $callback): void
    {
        static::$sendNotificationsUsing = $callback;
    }

    /**
     * The feedback categories offered in the widget. Accepts `key => label`
     * pairs, or a plain list of labels (keys are slugged from them). Pass an
     * empty array to hide the choice; null restores the defaults.
     *
     * @param  array<int|string, string>|Closure(): array<int|string, string>|null  $categories
     */
    public static function useCategories(array|Closure|null $categories): void
    {
        static::$categories = $categories;
    }

    /**
     * @return array<string, string>
     */
    public static function defaultCategories(): array
    {
        return [
            'change' => 'Change this',
            'missing' => 'Something missing',
            'broken' => 'Looks broken',
            'confusing' => 'Confusing',
            'works_well' => 'This works well',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function categories(): array
    {
        $categories = static::$categories instanceof Closure
            ? (static::$categories)()
            : (static::$categories ?? static::defaultCategories());

        return collect($categories)
            ->mapWithKeys(fn (string $label, int|string $key): array => [
                is_int($key) ? Str::snake(Str::slug($label, ' ')) : $key => $label,
            ])
            ->all();
    }

    /**
     * The label for a stored category key. A key that is no longer offered
     * still reads sensibly rather than disappearing.
     */
    public static function categoryLabel(?string $key): ?string
    {
        if (blank($key)) {
            return null;
        }

        return static::categories()[$key] ?? Str::headline($key);
    }

    /**
     * Switch the video link field on or off, overriding the config. Null
     * defers to `help-requests.video.enabled`.
     */
    public static function useVideoLinks(?bool $enabled): void
    {
        static::$videoLinksEnabled = $enabled;
    }

    public static function videoLinksEnabled(): bool
    {
        return static::$videoLinksEnabled ?? (bool) config('help-requests.video.enabled', true);
    }

    /**
     * Whether a pasted video link points at an accepted host. Subdomains of an
     * allowed host count (`www.loom.com` for `loom.com`).
     */
    public static function isAllowedVideoUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($scheme !== 'https' || $host === '') {
            return false;
        }

        $allowed = config('help-requests.video.allowed_hosts', []);

        if ($allowed === []) {
            return true;
        }

        foreach ($allowed as $allowedHost) {
            $allowedHost = strtolower($allowedHost);

            if ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost)) {
                return true;
            }
        }

        return false;
    }

    public static function usePanel(string $panelId): void
    {
        static::$panelId = $panelId;
    }

    public static function panelId(): ?string
    {
        return static::$panelId ?? Filament::getCurrentPanel()?->getId();
    }

    /**
     * @return class-string<Model&Authenticatable>
     */
    public static function userModel(): string
    {
        return config('help-requests.user_model', 'App\\Models\\User');
    }

    /**
     * Whether a user may triage help requests. Defaults to an `isAdmin()`
     * method on the user, then to the configured admin role.
     */
    public static function canManage(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (static::$authorizeUsing) {
            return (bool) (static::$authorizeUsing)($user);
        }

        if (method_exists($user, 'isAdmin')) {
            return (bool) $user->isAdmin();
        }

        if (method_exists($user, 'hasRole')) {
            return (bool) $user->hasRole(config('help-requests.admin_role'));
        }

        return false;
    }

    public static function canManageSettings(?Authenticatable $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (static::$authorizeSettingsUsing) {
            return (bool) (static::$authorizeSettingsUsing)($user);
        }

        return static::canManage($user);
    }

    /**
     * The users who hear about new requests and requester replies: the
     * configured recipient if they have an account, otherwise everyone with
     * the admin role.
     *
     * @return Collection<int, Model&Authenticatable>
     */
    public static function adminRecipients(): Collection
    {
        if (static::$adminRecipientsUsing) {
            return collect((static::$adminRecipientsUsing)());
        }

        $model = static::userModel();

        if (filled($recipient = config('help-requests.recipient'))) {
            $byEmail = $model::query()->where('email', $recipient)->get();

            if ($byEmail->isNotEmpty()) {
                return $byEmail->toBase();
            }
        }

        if (method_exists($model, 'scopeRole') && filled($role = config('help-requests.admin_role'))) {
            return $model::query()->role($role)->get()->toBase();
        }

        return collect();
    }

    /**
     * Deliver a requester-facing notification, through the app's own hook when
     * one is registered.
     *
     * @param  array<string, mixed>  $context
     */
    public static function notify(Authenticatable $user, Notification $notification, string $type, array $context = []): void
    {
        if (static::$sendNotificationsUsing) {
            (static::$sendNotificationsUsing)($user, $notification, $type, $context);

            return;
        }

        $user->notify($notification);
    }

    public static function userName(?object $user, string $fallback = 'Unknown'): string
    {
        if ($user === null) {
            return $fallback;
        }

        $name = $user->name
            ?? trim(($user->first_name ?? '').' '.($user->last_name ?? ''));

        return filled($name) ? $name : ($user->email ?? $fallback);
    }

    public static function userFirstName(?object $user, string $fallback = 'there'): string
    {
        if ($user === null) {
            return $fallback;
        }

        $first = $user->first_name ?? Str::before((string) ($user->name ?? ''), ' ');

        return filled($first) ? $first : $fallback;
    }

    public static function appName(): string
    {
        return (string) config('help-requests.branding.name', config('app.name'));
    }

    /**
     * The admin triage page for a request. Filament resource URLs need a
     * panel, which a queued job or webhook does not have — hence the explicit
     * panel id and the path fallback.
     */
    public static function adminUrl(HelpRequest $helpRequest): string
    {
        try {
            return HelpRequestResource::getUrl('view', ['record' => $helpRequest], panel: static::panelId());
        } catch (\Throwable) {
            return url("/admin/help-requests/{$helpRequest->getKey()}");
        }
    }

    /**
     * The page this recipient can actually open the request on.
     *
     * The admin resource would 403 an ordinary requester, so they are sent to
     * the panel home with `?help_request={id}`; the widget renders on every
     * panel page and opens straight onto that thread.
     */
    public static function urlFor(HelpRequest $helpRequest, ?object $recipient): string
    {
        if ($recipient instanceof Authenticatable && static::canManage($recipient)) {
            return static::adminUrl($helpRequest);
        }

        try {
            $home = Filament::getPanel(static::panelId())->getUrl() ?? url('/');
        } catch (\Throwable) {
            $home = url('/');
        }

        return $home.(str_contains($home, '?') ? '&' : '?').'help_request='.$helpRequest->getKey();
    }

    /**
     * Restore defaults. Used between tests.
     */
    public static function flush(): void
    {
        static::$authorizeUsing = null;
        static::$authorizeSettingsUsing = null;
        static::$adminRecipientsUsing = null;
        static::$sendNotificationsUsing = null;
        static::$panelId = null;
        static::$categories = null;
        static::$videoLinksEnabled = null;
    }
}
