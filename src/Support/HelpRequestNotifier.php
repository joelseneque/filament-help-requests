<?php

namespace Joelseneque\HelpRequests\Support;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Mail\HelpRequestSubmittedMail;
use Joelseneque\HelpRequests\Mail\HelpRequestUserRepliedMail;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestReply;
use Joelseneque\HelpRequests\Notifications\HelpRequestReplyNotification;
use Joelseneque\HelpRequests\Notifications\HelpRequestResolvedNotification;

class HelpRequestNotifier
{
    /**
     * Notification type keys passed to a custom delivery hook.
     */
    public const TYPE_REPLY = 'help_request_reply';

    public const TYPE_RESOLVED = 'help_request_resolved';

    public static function newRequestForAdmins(HelpRequest $helpRequest): void
    {
        if (filled($recipient = config('help-requests.recipient'))) {
            Mail::to($recipient)->send(new HelpRequestSubmittedMail($helpRequest));
        }

        $recipients = HelpRequests::adminRecipients();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title('New help request')
            ->body(static::withAuthor($helpRequest, $helpRequest->comment))
            ->icon('heroicon-o-lifebuoy')
            ->actions([
                Action::make('view')
                    ->button()
                    ->label('View request')
                    ->markAsRead()
                    ->url(HelpRequests::adminUrl($helpRequest)),
            ])
            ->sendToDatabase($recipients, isEventDispatched: true);
    }

    public static function userReplyForAdmins(HelpRequest $helpRequest, HelpRequestReply $reply): void
    {
        if (filled($recipient = config('help-requests.recipient'))) {
            Mail::to($recipient)->send(new HelpRequestUserRepliedMail($helpRequest, $reply));
        }

        $recipients = HelpRequests::adminRecipients();

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::make()
            ->title(HelpRequests::userFirstName($helpRequest->user, 'A user').' replied to a help request')
            ->body(Str::limit($reply->body, 120))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->actions([
                Action::make('view')
                    ->button()
                    ->label('View & reply')
                    ->markAsRead()
                    ->url(HelpRequests::adminUrl($helpRequest)),
            ])
            ->sendToDatabase($recipients, isEventDispatched: true);
    }

    /**
     * Tell the requester about a reply. The reply's own author is never
     * notified — someone who just typed a reply does not need telling about it.
     */
    public static function replyForUser(HelpRequest $helpRequest, HelpRequestReply $reply): void
    {
        $recipient = $helpRequest->user;

        if ($recipient === null || $recipient->is($reply->user)) {
            return;
        }

        HelpRequests::notify(
            user: $recipient,
            notification: new HelpRequestReplyNotification($helpRequest, $reply),
            type: self::TYPE_REPLY,
            context: [
                'help_request_id' => $helpRequest->getKey(),
                'author_name' => $reply->authorName(),
                'reply_excerpt' => Str::limit($reply->body, 200),
            ],
        );
    }

    /**
     * Tell the requester their request was resolved or closed, including the
     * closing comment when there was one.
     */
    public static function resolvedForUser(HelpRequest $helpRequest, ?HelpRequestReply $comment = null): void
    {
        $recipient = $helpRequest->user;

        if ($recipient === null) {
            return;
        }

        HelpRequests::notify(
            user: $recipient,
            notification: new HelpRequestResolvedNotification($helpRequest, $comment),
            type: self::TYPE_RESOLVED,
            context: [
                'help_request_id' => $helpRequest->getKey(),
                'status' => $helpRequest->status->getLabel(),
                'comment_excerpt' => $comment ? Str::limit($comment->body, 200) : null,
            ],
        );
    }

    protected static function withAuthor(HelpRequest $helpRequest, string $text): string
    {
        $name = HelpRequests::userFirstName($helpRequest->user, '');

        return ($name !== '' ? "{$name}: " : '').Str::limit($text, 100);
    }
}
