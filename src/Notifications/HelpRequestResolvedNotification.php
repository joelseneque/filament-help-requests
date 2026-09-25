<?php

namespace Joelseneque\HelpRequests\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Mail\HelpRequestResolvedMail;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestReply;
use Joelseneque\HelpRequests\Notifications\Concerns\HasChannelPreference;

/**
 * Tells the requester their help request was resolved or closed, carrying the
 * closing comment when one was given.
 */
class HelpRequestResolvedNotification extends Notification
{
    use HasChannelPreference;
    use Queueable;

    public function __construct(
        public HelpRequest $helpRequest,
        public ?HelpRequestReply $comment = null,
    ) {}

    /**
     * @return array<string>
     */
    public function via(object $notifiable): array
    {
        return $this->resolveChannels();
    }

    public function toMail(object $notifiable): HelpRequestResolvedMail
    {
        return (new HelpRequestResolvedMail($this->helpRequest, $this->comment))
            ->to($notifiable->email);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        $status = $this->helpRequest->status->getLabel();

        return FilamentNotification::make()
            ->title("Your help request was {$status}")
            ->body(Str::limit($this->comment?->body ?? $this->helpRequest->comment, 120))
            ->icon('heroicon-o-check-circle')
            ->actions([
                Action::make('view')
                    ->button()
                    ->label('View request')
                    ->markAsRead()
                    ->url(HelpRequests::urlFor($this->helpRequest, $notifiable)),
            ])
            ->getDatabaseMessage();
    }
}
