<?php

namespace App\Mail;

use App\Models\DemandAlert;
use App\Models\ProspectVendor;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** To a hardware store not on the platform yet: shoppers want something you may carry. */
class ProspectDemandMail extends Mailable
{
    public function __construct(public DemandAlert $alert, public ProspectVendor $prospect) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Shoppers are looking for {$this->alert->subject_label}");
    }

    public function headers(): Headers
    {
        return new Headers(text: ['List-Unsubscribe' => '<mailto:'.config('mail.from.address').'?subject=unsubscribe>']);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.demand.prospect', with: ['signupUrl' => config('hardwhere.demand.vendor_signup_url')]);
    }
}
