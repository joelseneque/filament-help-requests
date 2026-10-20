<?php

namespace Joelseneque\HelpRequests\Filament\Resources\HelpRequests\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Joelseneque\HelpRequests\Enums\HelpRequestStatus;
use Joelseneque\HelpRequests\Filament\Resources\HelpRequests\HelpRequestResource;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestReply;
use Joelseneque\HelpRequests\Services\GitHubIssueService;
use Joelseneque\HelpRequests\Support\HelpRequestNotifier;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ViewHelpRequest extends ViewRecord
{
    protected static string $resource = HelpRequestResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make('Request')
                    ->icon('heroicon-o-lifebuoy')
                    ->schema([
                        TextEntry::make('requester')
                            ->label('From')
                            ->state(fn (HelpRequest $record): string => HelpRequests::userName($record->user)),
                        TextEntry::make('user.email')
                            ->label('Email')
                            ->copyable()
                            ->icon('heroicon-m-envelope')
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->badge(),
                        TextEntry::make('created_at')
                            ->label('Submitted')
                            ->dateTime('d M Y, g:i A'),
                        TextEntry::make('category')
                            ->label('Type')
                            ->formatStateUsing(fn (?string $state): ?string => HelpRequests::categoryLabel($state))
                            ->badge()
                            ->color('gray')
                            ->placeholder('—'),
                        TextEntry::make('page_title')
                            ->label('Page')
                            ->placeholder('—'),
                        TextEntry::make('page_url')
                            ->label('URL')
                            ->url(fn (HelpRequest $record): ?string => $record->page_url)
                            ->openUrlInNewTab()
                            ->placeholder('—'),
                        TextEntry::make('comment')
                            ->label('Comment')
                            ->columnSpanFull(),
                        ViewEntry::make('video_url')
                            ->label('Video')
                            ->view('help-requests::components.video-embed')
                            ->columnSpanFull()
                            ->visible(fn (HelpRequest $record): bool => $record->hasVideo()),
                        ImageEntry::make('screenshots')
                            ->label('Screenshots')
                            ->state(fn (HelpRequest $record): array => $record->imageAttachmentPaths())
                            ->disk(config('help-requests.storage.disk'))
                            ->imageHeight(240)
                            ->columnSpanFull()
                            ->visible(fn (HelpRequest $record): bool => $record->imageAttachmentPaths() !== []),
                        self::fileLinks('files')
                            ->label('Files'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('GitHub')
                    ->icon('heroicon-o-code-bracket')
                    ->visible(fn (HelpRequest $record): bool => $record->hasGithubIssue())
                    ->schema([
                        TextEntry::make('github_issue_number')
                            ->label('Issue')
                            ->formatStateUsing(fn (?int $state): string => $state ? "#{$state}" : '—')
                            ->url(fn (HelpRequest $record): ?string => $record->github_issue_url)
                            ->openUrlInNewTab(),
                        TextEntry::make('github_repository')
                            ->label('Repository')
                            ->placeholder('—'),
                        TextEntry::make('github_issue_state')
                            ->label('Issue state')
                            ->badge()
                            ->color(fn (?string $state): string => $state === 'closed' ? 'success' : 'warning')
                            ->placeholder('—'),
                        TextEntry::make('github_synced_at')
                            ->label('Last synced')
                            ->since()
                            ->placeholder('Never'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Conversation')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->schema([
                        RepeatableEntry::make('replies')
                            ->hiddenLabel()
                            ->placeholder('No replies yet.')
                            ->schema([
                                TextEntry::make('author')
                                    ->label('')
                                    ->weight('bold')
                                    ->state(fn (HelpRequestReply $record): string => $record->authorName()),
                                TextEntry::make('created_at')
                                    ->label('')
                                    ->since(),
                                TextEntry::make('body')
                                    ->label('')
                                    ->columnSpanFull(),
                                ImageEntry::make('screenshots')
                                    ->label('')
                                    ->state(fn (HelpRequestReply $record): array => $record->imageAttachmentPaths())
                                    ->disk(config('help-requests.storage.disk'))
                                    ->imageHeight(160)
                                    ->columnSpanFull()
                                    ->visible(fn (HelpRequestReply $record): bool => $record->imageAttachmentPaths() !== []),
                                self::fileLinks('files')
                                    ->label(''),
                            ])
                            ->columns(2),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reply')
                ->icon('heroicon-o-arrow-uturn-left')
                ->schema([
                    Textarea::make('body')
                        ->label('Your reply')
                        ->required()
                        ->rows(4),
                    self::attachmentUpload(),
                    Select::make('status')
                        ->label('Set status')
                        ->options(HelpRequestStatus::class)
                        ->default(fn (HelpRequest $record): string => $record->status->value)
                        ->required(),
                ])
                ->action(function (array $data, HelpRequest $record): void {
                    $reply = $this->recordReply($record, $data['body'], $data['attachments'] ?? []);

                    $notifiedResolution = $this->applyStatus(
                        $record,
                        $this->normalizeStatus($data['status']),
                        $reply,
                    );

                    // A resolution email already carries this reply as its
                    // closing note, so sending both would be duplicate mail.
                    if (! $notifiedResolution) {
                        HelpRequestNotifier::replyForUser($record, $reply);
                    }

                    Notification::make()
                        ->title('Reply sent')
                        ->success()
                        ->send();
                }),

            Action::make('updateStatus')
                ->label('Update status')
                ->icon('heroicon-o-flag')
                ->color('gray')
                ->schema([
                    Select::make('status')
                        ->label('Status')
                        ->options(HelpRequestStatus::class)
                        ->default(fn (HelpRequest $record): string => $record->status->value)
                        ->live()
                        ->required(),
                    Textarea::make('comment')
                        ->label('Comment')
                        ->helperText(fn (Get $get): string => in_array($get('status'), [HelpRequestStatus::Resolved->value, HelpRequestStatus::Closed->value], true)
                            ? 'Included in the email telling them their request is done. Leave blank to send it without a note.'
                            : 'Optional — added to the conversation and posted to the GitHub issue.')
                        ->rows(4),
                    self::attachmentUpload()
                        ->helperText('Optional — attached to the comment above.'),
                ])
                ->action(function (array $data, HelpRequest $record): void {
                    $comment = filled($data['comment'] ?? null)
                        ? $this->recordReply($record, $data['comment'], $data['attachments'] ?? [])
                        : null;

                    $notifiedResolution = $this->applyStatus(
                        $record,
                        $this->normalizeStatus($data['status']),
                        $comment,
                    );

                    if ($comment && ! $notifiedResolution) {
                        HelpRequestNotifier::replyForUser($record, $comment);
                    }

                    Notification::make()
                        ->title('Status updated')
                        ->success()
                        ->send();
                }),

            Action::make('createGithubIssue')
                ->label('Create GitHub issue')
                ->icon('heroicon-o-code-bracket')
                ->color('gray')
                ->visible(fn (HelpRequest $record): bool => ! $record->hasGithubIssue() && app(GitHubIssueService::class)->isConfigured())
                ->action(function (HelpRequest $record, GitHubIssueService $github): void {
                    $result = $github->createIssue($record);

                    Notification::make()
                        ->title($result['ok'] ? 'GitHub issue created' : 'Could not create issue')
                        ->body($result['message'])
                        ->status($result['ok'] ? 'success' : 'danger')
                        ->send();
                }),

            Action::make('viewGithubIssue')
                ->label(fn (HelpRequest $record): string => "Issue #{$record->github_issue_number}")
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->visible(fn (HelpRequest $record): bool => $record->hasGithubIssue() && filled($record->github_issue_url))
                ->url(fn (HelpRequest $record): ?string => $record->github_issue_url)
                ->openUrlInNewTab(),

            Action::make('syncGithubIssue')
                ->label('Sync from GitHub')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn (HelpRequest $record): bool => $record->hasGithubIssue())
                ->action(function (HelpRequest $record, GitHubIssueService $github): void {
                    $result = $github->syncFromGithub($record);

                    Notification::make()
                        ->title($result['ok'] ? 'Synced from GitHub' : 'Could not sync')
                        ->body($result['message'])
                        ->status($result['ok'] ? 'success' : 'danger')
                        ->send();
                }),
        ];
    }

    /**
     * Download links for the attachments that are not images, which the
     * ImageEntry beside it cannot show.
     */
    protected static function fileLinks(string $name): TextEntry
    {
        return TextEntry::make($name)
            ->state(fn (HelpRequest|HelpRequestReply $record): array => array_map(
                fn (string $path): string => '<a href="'.e($record->attachmentUrl($path)).'" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">'.e($record->attachmentName($path)).'</a>',
                $record->fileAttachmentPaths(),
            ))
            ->html()
            ->listWithLineBreaks()
            ->columnSpanFull()
            ->visible(fn (HelpRequest|HelpRequestReply $record): bool => $record->fileAttachmentPaths() !== []);
    }

    /**
     * The optional screenshots and files that can be attached to a reply.
     * Each keeps its original name in its own folder, like widget uploads,
     * so the stored basename is the name shown.
     */
    protected static function attachmentUpload(): FileUpload
    {
        return FileUpload::make('attachments')
            ->label('Attachments')
            ->acceptedFileTypes(config('help-requests.storage.accepted_file_types'))
            ->multiple()
            ->maxFiles(config('help-requests.storage.max_files', 5))
            ->disk(config('help-requests.storage.disk'))
            ->directory(config('help-requests.storage.directory'))
            ->rules(['extensions:'.implode(',', HelpRequests::acceptedExtensions())])
            ->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file): string => Str::ulid().'/'.HelpRequests::attachmentFileName($file->getClientOriginalName()))
            ->visibility('public')
            ->maxSize(config('help-requests.storage.max_size_kb'))
            ->helperText('Optional — shown to the requester with your reply.');
    }

    /**
     * Record a reply and mirror it onto the linked GitHub issue.
     *
     * @param  list<string>  $attachments
     */
    protected function recordReply(HelpRequest $record, string $body, array $attachments = []): HelpRequestReply
    {
        $reply = $record->replies()->create([
            'user_id' => auth()->id(),
            'body' => $body,
            'attachments' => array_values($attachments) ?: null,
        ]);

        if ($record->hasGithubIssue()) {
            $result = app(GitHubIssueService::class)->postComment($reply);

            if (! $result['ok']) {
                Notification::make()
                    ->title('Not posted to GitHub')
                    ->body($result['message'])
                    ->warning()
                    ->send();
            }
        }

        return $reply;
    }

    /**
     * Apply a status locally and mirror it to the linked GitHub issue so the two
     * never drift — resolving here closes the issue, reopening here reopens it.
     *
     * Returns whether the requester was told the request is finished, so the
     * caller knows not to also send them a reply notification about the same text.
     */
    protected function applyStatus(HelpRequest $record, HelpRequestStatus $status, ?HelpRequestReply $comment = null): bool
    {
        $previousStatus = $record->status;

        $record->update([
            'status' => $status,
            'resolved_at' => $status === HelpRequestStatus::Resolved ? now() : null,
        ]);

        if ($record->hasGithubIssue()) {
            $result = app(GitHubIssueService::class)->pushStatus($record);

            if (! $result['ok']) {
                Notification::make()
                    ->title('GitHub was not updated')
                    ->body($result['message'])
                    ->warning()
                    ->send();
            }
        }

        $isFinished = $status->isFinished();

        // Only on the transition — re-saving an already-resolved request should
        // not email the requester again.
        if (! $isFinished || $previousStatus === $status) {
            return false;
        }

        HelpRequestNotifier::resolvedForUser($record, $comment);

        return true;
    }

    protected function normalizeStatus(HelpRequestStatus|string $status): HelpRequestStatus
    {
        return $status instanceof HelpRequestStatus ? $status : HelpRequestStatus::from($status);
    }
}
