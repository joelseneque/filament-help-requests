<?php

namespace Joelseneque\HelpRequests\Notifications;

use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Mail\HelpRequestRepliedMail;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestReply;
use Joelseneque\HelpRequests\Notifications\Concerns\HasChannelPreference;

/**
 * Tells the person who raised a help request that someone replied.
 */
class HelpRequestReplyNotification extends Notification
{
    use HasChannelPreference;
    use Queueable;

    public function __construct(
        public HelpRequest $helpRequest,
        public HelpRequestReply $reply,
    ) {}

    /**
     * @return array<string>
     */
    public function via(object $notifiable): array
    {
        return $this->resolveChannels();
    }

    public function toMail(object $notifiable): HelpRequestRepliedMail
    {
        return (new HelpRequestRepliedMail($this->helpRequest, $this->reply))
            ->to($notifiable->email);
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->reply->authorName().' replied to your help request')
            ->body(Str::limit($this->reply->body, 120))
            ->icon('heroicon-o-chat-bubble-left-right')
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
