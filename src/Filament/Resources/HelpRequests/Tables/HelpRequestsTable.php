<?php

namespace Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Models\HelpRequest;

class HelpRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('user'))
            ->columns([
                TextColumn::make('requester')
                    ->label('From')
                    ->state(fn (HelpRequest $record): string => HelpRequests::userName($record->user))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'user',
                        function (Builder $query) use ($search): void {
                            $query->where(function (Builder $query) use ($search): void {
                                foreach (config('help-requests.user_searchable_columns', ['name', 'email']) as $column) {
                                    $query->orWhere($column, 'like', "%{$search}%");
                                }
                            });
                        },
                    )),
                TextColumn::make('category')
                    ->label('Type')
                    ->formatStateUsing(fn (?string $state): ?string => HelpRequests::categoryLabel($state))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('comment')
                    ->label('Request')
                    ->limit(60)
                    ->wrap()
                    ->searchable(),
                IconColumn::make('video_url')
                    ->label('Video')
                    ->icon(fn (?string $state): ?string => filled($state) ? 'heroicon-o-play-circle' : null)
                    ->color('primary')
                    ->url(fn (HelpRequest $record): ?string => $record->video_url)
                    ->openUrlInNewTab()
                    ->alignCenter()
                    ->toggleable(),
                TextColumn::make('page_title')
                    ->label('Page')
                    ->placeholder('—')
                    ->limit(30)
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                TextColumn::make('github_issue_number')
                    ->label('GitHub')
                    ->formatStateUsing(fn (?int $state): string => $state ? "#{$state}" : '—')
                    ->url(fn (HelpRequest $record): ?string => $record->github_issue_url)
                    ->openUrlInNewTab()
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (HelpRequest $record): string => $record->github_issue_state === 'closed' ? 'success' : 'info')
                    ->toggleable(),
                TextColumn::make('replies_count')
                    ->label('Replies')
                    ->counts('replies')
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),
                TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime('d M Y, g:i A')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(HelpRequestStatus::class),
                SelectFilter::make('category')
                    ->label('Type')
                    ->options(fn (): array => HelpRequests::categories()),
                TernaryFilter::make('github_issue_number')
                    ->label('GitHub issue')
                    ->placeholder('All requests')
                    ->trueLabel('Linked to an issue')
                    ->falseLabel('Not linked')
                    ->nullable(),
            ])
            ->recordActions([
                ViewAction::make(),
                DeleteAction::make()
                    ->label(''),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
