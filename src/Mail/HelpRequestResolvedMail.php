<?php

namespace Joelseneque\HelpRequests\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Joelseneque\HelpRequests\Models\HelpRequest;
use Joelseneque\HelpRequests\Models\HelpRequestReply;

class HelpRequestResolvedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public HelpRequest $helpRequest,
        public ?HelpRequestReply $comment = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your Help Request Has Been '.$this->helpRequest->status->getLabel(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'help-requests::emails.help-request-resolved',
        );
    }
}
