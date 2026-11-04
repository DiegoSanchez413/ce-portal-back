<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class LoginCodeMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $body,
        public readonly string $code,
        public readonly string $language,
        public readonly string $supportPhone,
        public readonly ?string $sender = null,
    ) {}

    public function envelope(): Envelope
    {
        $from = $this->sender === null ? null : new Address($this->sender, (string) config('mail.from.name'));

        return new Envelope(from: $from, subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(html: 'client-portal.login-code-html', text: 'client-portal.login-code', with: [
            'es' => $this->language === 'es',
            'subject' => $this->subjectLine,
            'body' => $this->body,
            'code' => $this->code,
            'supportPhone' => $this->supportPhone,
            'logoUrl' => rtrim((string) config('app.url'), '/').'/images/ce-email-logo.png',
        ]);
    }
}
