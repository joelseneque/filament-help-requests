<?php

namespace Joelseneque\HelpRequests\Filament\Resources\HelpRequests;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Pages\ListHelpRequests;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Pages\ViewHelpRequest;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Tables\HelpRequestsTable;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\HelpRequestsPlugin;
use Joelseneque\HelpRequests\Models\HelpRequest;
use UnitEnum;

class HelpRequestResource extends Resource
{
    protected static ?string $model = HelpRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $navigationLabel = 'Help Requests';

    protected static ?string $slug = 'help-requests';

    public static function table(Table $table): Table
    {
        return HelpRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHelpRequests::route('/'),
            'view' => ViewHelpRequest::route('/{record}'),
        ];
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return static::plugin()?->getNavigationGroup();
    }

    public static function getNavigationSort(): ?int
    {
        return static::plugin()?->getNavigationSort();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = HelpRequest::query()->where('status', HelpRequestStatus::Open)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canViewAny(): bool
    {
        return HelpRequests::canManage(auth()->user());
    }

    public static function canView(Model $record): bool
    {
        return HelpRequests::canManage(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return HelpRequests::canManage(auth()->user());
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
