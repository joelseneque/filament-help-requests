<?php

namespace Joelseneque\HelpRequests\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\HelpRequestsPlugin;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestSetting;
use Joelseneque\HelpRequests\Services\GitHubAppTokenService;
use Joelseneque\HelpRequests\Services\GitHubIssueService;
use UnitEnum;

/**
 * Connects this database to a GitHub App installation and chooses how help
 * requests become issues.
 *
 * @property-read Schema $form
 */
class HelpRequestSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $navigationLabel = 'Help Requests';

    protected static ?string $title = 'Help Request Settings';

    protected static ?string $slug = 'help-request-settings';

    protected string $view = 'help-requests::pages.settings';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function getCluster(): ?string
    {
        return static::plugin()?->getSettingsCluster();
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return static::plugin()?->getSettingsNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return static::plugin()?->getSettingsNavigationSort();
    }

    public static function canAccess(): bool
    {
        return HelpRequests::canManageSettings(auth()->user());
    }

    public function mount(): void
    {
        $this->notifyFromInstallFlow();

        $this->form->fill($this->getRecord()?->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make($this->settingsSchema())
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->submit('save')
                                ->keyBindings(['mod+s']),
                        ]),
                    ]),
            ])
            ->record($this->getRecord())
            ->statePath('data');
    }

    public function save(): void
    {
        $record = $this->getRecord() ?? HelpRequestSetting::current();

        $record->fill($this->form->getState());
        $record->save();

        Notification::make()
            ->success()
            ->title('Settings saved')
            ->send();
    }

    public function getRecord(): ?HelpRequestSetting
    {
        return HelpRequestSetting::query()->first();
    }

    /**
     * Surface the outcome of the GitHub install redirect, which arrives as a
     * flashed message rather than a Livewire notification.
     */
    protected function notifyFromInstallFlow(): void
    {
        foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger'] as $key => $status) {
            if (session()->has($key)) {
                Notification::make()
                    ->title(session()->pull($key))
                    ->status($status)
                    ->send();
            }
        }
    }

    /**
     * @return array<int, Component>
     */
    protected function settingsSchema(): array
    {
        $appName = HelpRequests::appName();

        return [
            Section::make('GitHub Connection')
                ->description('Install the GitHub App to raise help requests as issues. GitHub handles the repository selection.')
                ->icon(Heroicon::OutlinedCodeBracket)
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    Placeholder::make('github_status')
                        ->label('Connection Status')
                        ->content(fn (): string => $this->connectionSummary($this->getRecord()))
                        ->columnSpanFull(),

                    Placeholder::make('github_connect')
                        ->label('Authorisation')
                        ->content(fn () => view('help-requests::components.github-connect-button', [
                            'record' => $this->getRecord(),
                            'configured' => $this->hasAppCredentials(),
                            'appName' => $appName,
                        ]))
                        ->columnSpanFull(),

                    Select::make('github_repository')
                        ->label('Repository')
                        ->helperText('Only repositories included in the app installation appear here. Use "Configure repositories" above to add more.')
                        ->options(fn (): array => app(GitHubIssueService::class)->availableRepositories())
                        ->searchable()
                        ->native(false)
                        ->placeholder('Select a repository')
                        ->visible(fn (): bool => (bool) $this->getRecord()?->isGithubInstalled())
                        ->columnSpanFull(),
                ]),

            Section::make('Issue Creation')
                ->description('How help requests are turned into issues.')
                ->icon(Heroicon::OutlinedSquare3Stack3d)
                ->columns(2)
                ->columnSpanFull()
                ->visible(fn (): bool => (bool) $this->getRecord()?->isGithubInstalled())
                ->schema([
                    Toggle::make('github_enabled')
                        ->label('Enable GitHub integration')
                        ->helperText('Turn off to pause issue creation and syncing without disconnecting the app.')
                        ->columnSpanFull(),

                    Toggle::make('github_auto_create')
                        ->label('Create an issue automatically for every new help request')
                        ->helperText('Leave off to create issues manually from the help request page.')
                        ->columnSpanFull(),

                    TagsInput::make('github_default_labels')
                        ->label('Default labels')
                        ->placeholder('Add a label')
                        ->helperText('Applied to every issue created from a help request. Labels must already exist in the repository or GitHub will reject the issue.')
                        ->columnSpanFull(),
                ]),

            Section::make('Webhook')
                ->description('Configured once on the GitHub App itself — not per repository.')
                ->icon(Heroicon::OutlinedBolt)
                ->columns(2)
                ->columnSpanFull()
                ->collapsed()
                ->schema([
                    Placeholder::make('github_webhook_url')
                        ->label('Payload URL')
                        ->content(route('help-requests.webhook'))
                        ->columnSpanFull(),

                    Placeholder::make('github_webhook_help')
                        ->label('Setup')
                        ->content(new HtmlString(
                            'In your GitHub App settings set the <strong>Webhook URL</strong> to the address above, add the same secret '
                            .'you put in <code>GITHUB_APP_WEBHOOK_SECRET</code>, and subscribe to the <strong>Issues</strong> and '
                            .'<strong>Installation</strong> events. Set the <strong>Setup URL</strong> to <code>'.e(route('help-requests.github.callback')).'</code>.<br><br>'
                            .'Closing an issue marks the request <strong>Resolved</strong>. Closing it as <em>not planned</em> marks it '
                            .'<strong>Closed</strong>. Reopening it marks it <strong>Open</strong> again.<br><br>'
                            .'Comments on the issue are <strong>not</strong> copied back into the help request — they stay in GitHub as the '
                            .'dev team\'s internal discussion. To say something to the requester, use <strong>Reply</strong> on the request; '
                            .'that reply is posted to the issue as well.'
                        ))
                        ->columnSpanFull(),
                ]),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testGithubConnection')
                ->label('Test connection')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->action(function (GitHubIssueService $github): void {
                    $result = $github->testConnection();

                    Notification::make()
                        ->title($result['ok'] ? 'GitHub connected' : 'GitHub connection failed')
                        ->body($result['message'])
                        ->status($result['ok'] ? 'success' : 'danger')
                        ->send();
                }),

            Action::make('refreshRepositories')
                ->label('Refresh repositories')
                ->icon(Heroicon::OutlinedArrowPathRoundedSquare)
                ->color('gray')
                ->visible(fn (): bool => (bool) $this->getRecord()?->isGithubInstalled())
                ->action(function (GitHubIssueService $github): void {
                    $count = count($github->availableRepositories(fresh: true));

                    Notification::make()
                        ->title('Repository list refreshed')
                        ->body("{$count} repository(s) available to the installation.")
                        ->success()
                        ->send();
                }),

            Action::make('syncGithubIssues')
                ->label('Sync now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => (bool) $this->getRecord()?->isGithubConnected())
                ->requiresConfirmation()
                ->modalDescription('Fetches the current state of every linked GitHub issue and updates the matching help requests.')
                ->action(function (GitHubIssueService $github): void {
                    $synced = 0;
                    $failed = 0;

                    HelpRequest::query()
                        ->whereNotNull('github_issue_number')
                        ->each(function (HelpRequest $helpRequest) use ($github, &$synced, &$failed): void {
                            if ($github->syncFromGithub($helpRequest)['ok']) {
                                $synced++;

                                return;
                            }

                            $failed++;
                        });

                    $this->getRecord()?->update(['github_last_synced_at' => now()]);

                    Notification::make()
                        ->title('Sync complete')
                        ->body("{$synced} request(s) updated".($failed > 0 ? ", {$failed} could not be synced." : '.'))
                        ->status($failed > 0 ? 'warning' : 'success')
                        ->send();
                }),
        ];
    }

    /**
     * Forget the installation locally. The app stays installed in GitHub until
     * it is removed there, so the message says so rather than implying a full
     * revocation that did not happen.
     */
    public function disconnectGithub(): void
    {
        $record = $this->getRecord();

        if (! $record) {
            return;
        }

        $record->update([
            'github_installation_id' => null,
            'github_account_login' => null,
            'github_installation_token' => null,
            'github_installation_token_expires_at' => null,
            'github_installation_failed_at' => null,
            'github_repository' => null,
            'github_enabled' => false,
        ]);

        $this->form->fill($record->attributesToArray());

        Notification::make()
            ->success()
            ->title('GitHub disconnected')
            ->body(HelpRequests::appName().' will no longer raise or sync issues. To fully revoke access, uninstall the app in GitHub too.')
            ->send();
    }

    protected function hasAppCredentials(): bool
    {
        return app(GitHubAppTokenService::class)->isConfigured()
            && filled(config('help-requests.github.app_slug'));
    }

    protected function connectionSummary(?HelpRequestSetting $record): string
    {
        if (! $this->hasAppCredentials()) {
            return '⚠️ GitHub App credentials are not configured in the .env file.';
        }

        if (! $record?->isGithubInstalled()) {
            return 'Not connected — install the app to start raising issues from help requests.';
        }

        $account = $record->github_account_login ? " to {$record->github_account_login}" : '';

        if ($record->githubNeedsReconnect()) {
            return '⚠️ The installation'.$account.' was removed or suspended in GitHub — please reconnect.';
        }

        if (blank($record->github_repository)) {
            return '✅ Connected'.$account.' — now choose a repository below.';
        }

        if (! $record->github_enabled) {
            return '⏸ Connected'.$account.' to '.$record->githubRepository().', but the integration is switched off.';
        }

        $lastSynced = $record->github_last_synced_at
            ? ' Last full sync '.$record->github_last_synced_at->diffForHumans().'.'
            : '';

        return '✅ Raising help requests in '.$record->githubRepository().'.'.$lastSynced;
    }

    protected static function plugin(): ?HelpRequestsPlugin
    {
        try {
            return HelpRequestsPlugin::get();
        } catch (\Throwable) {
            return null;
        }
    }
}
