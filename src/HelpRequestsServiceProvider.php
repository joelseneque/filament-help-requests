<?php

namespace Joelseneque\HelpRequests;

use Illuminate\Console\Scheduling\Schedule;
use Joelseneque\HelpRequests\Console\Commands\GitHubIntegrationStatusCommand;
use Joelseneque\HelpRequests\Console\Commands\SyncGitHubIssuesCommand;
use Joelseneque\HelpRequests\Livewire\HelpRequestWidget;
use Livewire\Livewire;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class HelpRequestsServiceProvider extends PackageServiceProvider
{
    public static string $name = 'help-requests';

    public function configurePackage(Package $package): void
    {
        $package
            ->name(static::$name)
            ->hasConfigFile()
            ->hasViews('help-requests')
            ->hasRoute('web')
            ->hasMigrations([
                'create_help_requests_tables',
                'create_help_request_settings_table',
                'add_category_to_help_requests_table',
                'add_video_url_to_help_requests_table',
            ])
            ->hasCommands([
                GitHubIntegrationStatusCommand::class,
                SyncGitHubIssuesCommand::class,
            ]);
    }

    public function packageBooted(): void
    {
        Livewire::component('help-requests-widget', HelpRequestWidget::class);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! config('help-requests.github.schedule_sync')) {
                return;
            }

            // Catches GitHub issue status changes a webhook delivery missed.
            $schedule->command('help-requests:sync-github')->hourly()->withoutOverlapping();
        });
    }
}
