<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use App\Modules\ClientPortal\Domain\LoginException;
use App\Modules\ClientPortal\Mail\LoginCodeMail;
use App\Modules\Shared\Soft\SoftGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

// Entrega el código: SMS por la línea de RingCentral del Soft o correo por el SMTP.
final class CodeSender
{
    public function __construct(
        private readonly SoftGateway $soft,
        private readonly MessageTemplates $templates,
    ) {}

    public function send(string $channel, string $destination, string $code, string $language): void
    {
        try {
            if ($channel === 'email') {
                $email = $this->templates->codeEmail($code, $language);
                Mail::to($destination)->send(new LoginCodeMail($email['subject'], $email['body'], $code, $language, $email['phone'], $email['sender']));

                return;
            }

            $text = $this->templates->codeSms($code, $language);

            if (config('client_portal.sms_driver') === 'log' && ! app()->isProduction()) {
                Log::info('client_portal.sms', ['mobile' => $destination, 'text' => $text]);

                return;
            }

            $response = $this->soft->post('sms', ['mobile' => $destination, 'text' => $text]);
        } catch (Throwable $e) {
            Log::error('client_portal.code_not_sent', ['channel' => $channel, 'error' => $e->getMessage()]);

            throw LoginException::sendFailed();
        }

        if ($response->failed()) {
            Log::error('client_portal.code_not_sent', ['channel' => $channel, 'status' => $response->status()]);

            throw LoginException::sendFailed();
        }
    }
}
