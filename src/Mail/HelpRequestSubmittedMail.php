<?php

namespace Joelseneque\HelpRequests\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Joelseneque\HelpRequests\HelpRequests;
use Joelseneque\HelpRequests\Models\HelpRequest;

class HelpRequestSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public HelpRequest $helpRequest) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New Help Request from '.HelpRequests::userFirstName($this->helpRequest->user, 'a user'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'help-requests::emails.help-request-submitted',
            with: [
                'viewUrl' => HelpRequests::adminUrl($this->helpRequest),
            ],
        );
    }
}
