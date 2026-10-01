<?php

namespace Spdotdev\Inventory\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $resetUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your Inventory password');
    }

    public function content(): Content
    {
        // Larastan >= 3.12 checks `view-string` literals against the view
        // finder, which cannot resolve this package's `inventory::` namespace
        // during static analysis. The view lives at
        // resources/views/emails/password-reset.blade.php.
        /** @var view-string $view */
        $view = 'inventory::emails.password-reset';

        return new Content(view: $view);
    }
}
