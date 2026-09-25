<?php

namespace Joelseneque\HelpRequests\Tests\Fixtures;

use Filament\Http\Middleware\Authenticate;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Joelseneque\HelpRequests\HelpRequestsPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->databaseNotifications()
            ->pages([Dashboard::class])
            ->plugin(HelpRequestsPlugin::make())
            ->middleware([
                EncryptCookies::class,
                StartSession::class,
                ShareErrorsFromSession::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
