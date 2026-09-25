<?php

namespace Joelseneque\HelpRequests;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Blade;
use Joelseneque\HelpRequests\Filament\Pages\HelpRequestSettings;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\HelpRequestResource;
use UnitEnum;

class HelpRequestsPlugin implements Plugin
{
    protected bool $hasWidget = true;

    protected bool $hasResource = true;

    protected bool $hasSettingsPage = true;

    protected string|UnitEnum|null $navigationGroup = null;

    protected ?int $navigationSort = 90;

    protected ?string $settingsCluster = null;

    protected string|UnitEnum|null $settingsNavigationGroup = null;

    protected ?int $settingsNavigationSort = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(app(static::class)->getId());
    }

    public function getId(): string
    {
        return 'help-requests';
    }

    public function register(Panel $panel): void
    {
        // Links in emails, jobs and the webhook point at the panel that holds
        // the admin pages, so with several panels only that one is recorded.
        if ($this->hasResource) {
            HelpRequests::usePanel($panel->getId());
        }

        $panel
            ->resources($this->hasResource ? [HelpRequestResource::class] : [])
            ->pages($this->hasSettingsPage ? [HelpRequestSettings::class] : []);

        if ($this->hasWidget) {
            $panel->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => auth()->check() ? Blade::render("@livewire('help-requests-widget')") : '',
            );
        }
    }

    public function boot(Panel $panel): void {}

    /**
     * Show the floating help button on every page of the panel.
     */
    public function widget(bool $condition = true): static
    {
        $this->hasWidget = $condition;

        return $this;
    }

    /**
     * Register the admin triage resource in this panel.
     */
    public function resource(bool $condition = true): static
    {
        $this->hasResource = $condition;

        return $this;
    }

    /**
     * Register the GitHub settings page in this panel.
     */
    public function settingsPage(bool $condition = true): static
    {
        $this->hasSettingsPage = $condition;

        return $this;
    }

    /**
     * Who can see and answer help requests. Defaults to `$user->isAdmin()`,
     * then to the `admin_role` config value.
     *
     * @param  Closure(Authenticatable $user): bool  $callback
     */
    public function authorizeUsing(Closure $callback): static
    {
        HelpRequests::authorizeUsing($callback);

        return $this;
    }

    /**
     * Who can open the GitHub settings page. Defaults to the same check as
     * {@see authorizeUsing()}.
     *
     * @param  Closure(Authenticatable $user): bool  $callback
     */
    public function authorizeSettingsUsing(Closure $callback): static
    {
        HelpRequests::authorizeSettingsUsing($callback);

        return $this;
    }

    /**
     * Which users get the in-app notification for new requests and replies.
     *
     * @param  Closure(): iterable<Authenticatable>  $callback
     */
    public function adminRecipientsUsing(Closure $callback): static
    {
        HelpRequests::resolveAdminRecipientsUsing($callback);

        return $this;
    }

    /**
     * Take over delivery of the notifications sent to a requester.
     *
     * @param  Closure(Authenticatable $user, Notification $notification, string $type, array<string, mixed> $context): void  $callback
     */
    public function sendNotificationsUsing(Closure $callback): static
    {
        HelpRequests::sendNotificationsUsing($callback);

        return $this;
    }

    /**
     * The feedback categories shown as buttons in the widget, replacing the
     * defaults ("Change this", "Something missing", "Looks broken",
     * "Confusing", "This works well"). Pass `key => label` pairs or a list of
     * labels; pass an empty array to hide the choice.
     *
     * @param  array<int|string, string>|Closure(): array<int|string, string>  $categories
     */
    public function categories(array|Closure $categories): static
    {
        HelpRequests::useCategories($categories);

        return $this;
    }

    /**
     * Offer the optional Loom (screen recording) link on new requests. On by
     * default; `->videoLinks(false)` removes the field.
     */
    public function videoLinks(bool $condition = true): static
    {
        HelpRequests::useVideoLinks($condition);

        return $this;
    }

    public function navigationGroup(string|UnitEnum|null $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        return $this->navigationGroup;
    }

    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        return $this->navigationSort;
    }

    /**
     * Place the settings page inside one of the app's Filament clusters.
     *
     * @param  class-string|null  $cluster
     */
    public function settingsCluster(?string $cluster): static
    {
        $this->settingsCluster = $cluster;

        return $this;
    }

    public function getSettingsCluster(): ?string
    {
        return $this->settingsCluster;
    }

    public function settingsNavigationGroup(string|UnitEnum|null $group): static
    {
        $this->settingsNavigationGroup = $group;

        return $this;
    }

    public function getSettingsNavigationGroup(): string|UnitEnum|null
    {
        return $this->settingsNavigationGroup;
    }

    public function settingsNavigationSort(?int $sort): static
    {
        $this->settingsNavigationSort = $sort;

        return $this;
    }

    public function getSettingsNavigationSort(): ?int
    {
        return $this->settingsNavigationSort;
    }
}
