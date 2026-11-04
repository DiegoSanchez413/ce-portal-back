<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// Textos del código que el CEO edita en el Soft (client_portal_message_templates); sin plantilla, los del archivo de idioma.
final class MessageTemplates
{
    public const WEB_OTP = "\n\n@my.creditexperts.us #:code";

    public function codeSms(string $code, string $language): string
    {
        $body = $this->field('ACCESS_CODE_SMS', 'body', $language, '@code');
        if ($body === null) {
            return __('client_portal.code_sms', ['code' => $code], $language);
        }

        return str_replace('@code', $code, $body).str_replace(':code', $code, self::WEB_OTP);
    }

    /** @return array{subject: string, body: string, sender: ?string, phone: string} */
    public function codeEmail(string $code, string $language): array
    {
        $phone = $this->supportPhone();
        $subject = $this->field('ACCESS_CODE_EMAIL', 'subject', $language);
        $body = $this->field('ACCESS_CODE_EMAIL', 'body', $language, '@code');
        if ($subject === null || $body === null) {
            return [
                'subject' => __('client_portal.code_email_subject', [], $language),
                'body' => __('client_portal.code_email_body', ['code' => $code, 'phone' => $phone], $language),
                'sender' => null,
                'phone' => $phone,
            ];
        }

        $sender = DB::table('client_portal_message_templates')->where('type', 'ACCESS_CODE_EMAIL')->value('sender_email');
        $vars = ['@code' => $code, '@phone' => $phone];

        return [
            'subject' => strtr($subject, $vars),
            'body' => strtr($body, $vars),
            'sender' => is_string($sender) && $sender !== '' ? $sender : null,
            'phone' => $phone,
        ];
    }

    // Teléfono de Customer Care para llamadas; los SMS del portal salen por la línea CS Personal.
    public function supportPhone(): string
    {
        return (string) config('client_portal.support_phone');
    }

    // Texto guardado del idioma; vacío o sin la variable obligatoria cuenta como sin plantilla.
    private function field(string $type, string $field, string $language, ?string $required = null): ?string
    {
        $column = $field.'_'.($language === 'es' ? 'es' : 'en');
        try {
            $value = DB::table('client_portal_message_templates')->where('type', $type)->value($column);
        } catch (Throwable $e) {
            Log::warning('client_portal.template_unavailable', ['type' => $type, 'error' => $e->getMessage()]);

            return null;
        }

        if (! is_string($value) || trim($value) === '' || ($required !== null && ! str_contains($value, $required))) {
            return null;
        }

        return $value;
    }
}
