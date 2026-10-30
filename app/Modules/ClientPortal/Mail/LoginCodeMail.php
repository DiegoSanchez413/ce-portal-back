<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class LoginCodeMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $code,
        public readonly string $language,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('client_portal.code_email_subject', [], $this->language));
    }

    public function content(): Content
    {
        return new Content(text: 'client-portal.login-code', with: [
            'body' => __('client_portal.code_email_body', ['code' => $this->code], $this->language),
        ]);
    }
}
