<?php

namespace App\Mail;

use App\Models\DemandAlert;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** To a member tenant's owner / inventory manager: demand is building, prepare stock. */
class DemandAlertMail extends Mailable
{
    public function __construct(public DemandAlert $alert, public string $tenantName, public string $recipientName, public bool $alreadyListed) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Demand alert: {$this->alert->searcher_count} shoppers looking for {$this->alert->subject_label}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.demand.member');
    }
}
