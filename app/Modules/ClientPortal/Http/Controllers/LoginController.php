<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Http\Controllers;

use App\Modules\ClientPortal\Application\AccessDecision;
use App\Modules\ClientPortal\Application\ClientAccess;
use App\Modules\ClientPortal\Application\Reactivation;
use App\Modules\ClientPortal\Domain\LoginException;
use App\Modules\ClientPortal\Domain\LoginIdentifier;
use App\Modules\ClientPortal\Domain\Phone;
use App\Modules\ClientPortal\Http\ClientOptions;
use App\Modules\ClientPortal\Http\PortalCookie;
use App\Modules\ClientPortal\Infrastructure\ChallengeStore;
use App\Modules\ClientPortal\Infrastructure\ClientDirectory;
use App\Modules\ClientPortal\Infrastructure\LoginChallenge;
use App\Modules\ClientPortal\Infrastructure\OtpService;
use App\Modules\ClientPortal\Infrastructure\PortalSettings;
use App\Modules\ClientPortal\Infrastructure\Turnstile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Ingreso con código: identificar al cliente por celular o cuenta, enviar y validar el código,
 * elegir cliente en un celular compartido y pedir reactivación desde la pantalla sin acceso.
 */
final class LoginController
{
    public function __construct(
        private readonly ChallengeStore $challenges,
        private readonly OtpService $otp,
        private readonly ClientDirectory $directory,
        private readonly PortalSettings $settings,
        private readonly ClientAccess $access,
        private readonly Reactivation $reactivation,
        private readonly ClientOptions $options,
    ) {}

    #[OA\Post(
        path: '/auth/code',
        operationId: 'requestCode',
        summary: 'Identify the client and send the sign-in code by SMS',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['identifier', 'language'],
            properties: [
                new OA\Property(property: 'identifier', type: 'string', example: '(555) 123-4567'),
                new OA\Property(property: 'language', type: 'string', enum: ['en', 'es']),
                new OA\Property(property: 'turnstile_token', type: 'string', nullable: true),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Code screen', content: new OA\JsonContent(ref: '#/components/schemas/CodeChallenge')),
            new OA\Response(response: 422, description: 'Invalid identifier or Turnstile', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
            new OA\Response(response: 423, description: 'Locked', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
        ],
    )]
    public function requestCode(Request $request, Turnstile $turnstile): JsonResponse
    {
        $data = $request->validate([
            'identifier' => ['required', 'string', 'max:30'],
            'language' => ['required', 'in:en,es'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ]);

        if (! $turnstile->passes($data['turnstile_token'] ?? null, $request->ip())) {
            return $this->error('turnstile_failed', 'Please try again.', 422);
        }

        $identifier = LoginIdentifier::parse((string) $data['identifier']);
        if ($identifier === null) {
            return $this->error('invalid_identifier', 'Enter a valid mobile number or account number.', 422);
        }

        $challenge = $this->createChallenge($identifier, (string) $data['language'], $request);
        if ($challenge === null) {
            return $this->error('invalid_identifier', 'Enter a valid mobile number or account number.', 422);
        }

        return $this->send($challenge, 'sms', $request);
    }

    #[OA\Post(
        path: '/auth/resend',
        operationId: 'resendCode',
        summary: 'Send a new code by SMS or email',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['challenge_id', 'channel'],
            properties: [
                new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'channel', type: 'string', enum: ['sms', 'email']),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Code screen', content: new OA\JsonContent(ref: '#/components/schemas/CodeChallenge')),
            new OA\Response(response: 410, description: 'Expired sign-in', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
            new OA\Response(response: 423, description: 'Locked', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
            new OA\Response(response: 429, description: 'Too soon', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
        ],
    )]
    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'channel' => ['required', 'in:sms,email'],
        ]);

        $challenge = $this->challenges->find((string) $data['challenge_id']);
        if ($challenge === null || $challenge->verifiedAt !== null) {
            return $this->loginError(LoginException::invalidChallenge());
        }
        if ($data['channel'] === 'email' && $challenge->email === null) {
            return $this->loginError(LoginException::noEmail());
        }

        return $this->send($challenge, (string) $data['channel'], $request);
    }

    #[OA\Post(
        path: '/auth/verify',
        operationId: 'verifyCode',
        summary: 'Validate the code and sign in',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['challenge_id', 'code', 'keep_signed_in'],
            properties: [
                new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'code', type: 'string', pattern: '^\d{6}$'),
                new OA\Property(property: 'keep_signed_in', type: 'boolean'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Signed in, client selector or no access', content: new OA\JsonContent(ref: '#/components/schemas/SignInResult')),
            new OA\Response(response: 422, description: 'Incorrect or expired code', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
            new OA\Response(response: 423, description: 'Locked', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
        ],
    )]
    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'digits:6'],
            'keep_signed_in' => ['required', 'boolean'],
        ]);

        $challenge = $this->challenges->find((string) $data['challenge_id']);
        if ($challenge === null || $challenge->verifiedAt !== null) {
            return $this->loginError(LoginException::invalidChallenge());
        }

        try {
            $channel = $this->otp->verify($challenge, (string) $data['code']);
        } catch (LoginException $e) {
            return $this->loginError($e);
        }

        $this->challenges->markVerified($challenge, $channel);
        $challenge = $this->challenges->find($challenge->id) ?? $challenge;

        $eligible = $this->access->eligibleLeads($challenge);
        if ($challenge->loginMethod === 'phone' && count($eligible) > 1) {
            return response()->json([
                'status' => 'select_client',
                'clients' => $this->options->of($eligible),
            ]);
        }

        $leadId = $eligible[0] ?? $challenge->leadIds[0];

        return $this->decide($challenge, $leadId, (bool) $data['keep_signed_in'], $request);
    }

    #[OA\Post(
        path: '/auth/select-client',
        operationId: 'selectClient',
        summary: 'Choose the client on a shared phone',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['challenge_id', 'lead_id', 'keep_signed_in'],
            properties: [
                new OA\Property(property: 'challenge_id', type: 'string', format: 'uuid'),
                new OA\Property(property: 'lead_id', type: 'integer'),
                new OA\Property(property: 'keep_signed_in', type: 'boolean'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Signed in or no access', content: new OA\JsonContent(ref: '#/components/schemas/SignInResult')),
            new OA\Response(response: 410, description: 'Expired sign-in', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
        ],
    )]
    public function selectClient(Request $request): JsonResponse
    {
        $data = $request->validate([
            'challenge_id' => ['required', 'uuid'],
            'lead_id' => ['required', 'integer'],
            'keep_signed_in' => ['required', 'boolean'],
        ]);

        $challenge = $this->challenges->find((string) $data['challenge_id']);
        $leadId = (int) $data['lead_id'];

        if ($challenge === null || $challenge->verifiedAt === null || ! in_array($leadId, $this->access->eligibleLeads($challenge), true)) {
            return $this->loginError(LoginException::invalidChallenge());
        }

        return $this->decide($challenge, $leadId, (bool) $data['keep_signed_in'], $request);
    }

    #[OA\Post(
        path: '/auth/reactivation',
        operationId: 'requestReactivation',
        summary: 'Ask the specialist to reactivate the service (no-access screen)',
        tags: ['Auth'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['token'],
            properties: [new OA\Property(property: 'token', type: 'string')],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Request registered or still blocked', content: new OA\JsonContent(ref: '#/components/schemas/ReactivationStatus')),
            new OA\Response(response: 410, description: 'Expired token', content: new OA\JsonContent(ref: '#/components/schemas/LoginError')),
        ],
    )]
    public function reactivation(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:2048']]);

        $target = $this->reactivation->readToken((string) $data['token']);
        if ($target === null || $target['account_id'] === '') {
            return $this->loginError(LoginException::invalidChallenge());
        }

        $result = $this->reactivation->request($target['lead_id'], $target['account_id']);

        return response()->json([
            'sent' => $result['sent'],
            'available_at' => $result['available_at']->toIso8601String(),
        ]);
    }

    private function createChallenge(LoginIdentifier $identifier, string $language, Request $request): ?LoginChallenge
    {
        if ($identifier->method === 'account') {
            $account = $this->directory->accountByNumber($identifier->value);
            $lead = $account === null ? null : $this->directory->lead((int) $account->lead_id);
            $mobile = $lead === null ? null : Phone::format((string) $lead->mobile);

            if ($account === null || $lead === null || $mobile === null) {
                return null;
            }

            return $this->challenges->create('account', $mobile, $this->email($lead->email), [(int) $lead->id], (string) $account->id,
                $this->settings->language((int) $lead->id) ?? $language, $request->ip());
        }

        $leads = $this->directory->leadsByMobile($identifier->value);
        $first = $leads[0] ?? null;
        $email = count($leads) === 1 && $first !== null ? $this->email($first->email) : null;
        $savedLanguage = count($leads) === 1 && $first !== null ? $this->settings->language((int) $first->id) : null;

        return $this->challenges->create('phone', $identifier->value, $email,
            array_map(fn (\stdClass $lead): int => (int) $lead->id, $leads), null, $savedLanguage ?? $language, $request->ip());
    }

    private function send(LoginChallenge $challenge, string $channel, Request $request): JsonResponse
    {
        try {
            $resendAt = $this->otp->issue($challenge, $channel, $request->ip());
        } catch (LoginException $e) {
            return $this->loginError($e);
        }

        return response()->json([
            'challenge_id' => $challenge->id,
            'channel' => $channel,
            'destination' => $channel === 'email' ? Phone::maskEmail((string) $challenge->email) : Phone::mask($challenge->mobile),
            'mobile' => Phone::mask($challenge->mobile),
            'email' => $challenge->email === null ? null : Phone::maskEmail($challenge->email),
            'resend_available_at' => $resendAt->toIso8601String(),
            'expires_in' => OtpService::TTL_MINUTES * 60,
        ]);
    }

    private function decide(LoginChallenge $challenge, int $leadId, bool $keepSignedIn, Request $request): JsonResponse
    {
        $decision = $this->access->enter($challenge, $leadId, $keepSignedIn, $request);
        $this->challenges->close($challenge);

        if (! $decision->isGranted()) {
            return $this->noAccess($decision);
        }

        return response()->json(['status' => 'signed_in'])
            ->withCookie(PortalCookie::make((string) $decision->token, $keepSignedIn));
    }

    private function noAccess(AccessDecision $decision): JsonResponse
    {
        $accountId = (string) $decision->accountId;

        return response()->json([
            'status' => 'no_access',
            'reactivation' => [
                'token' => $accountId === '' ? null : $this->reactivation->token((int) $decision->leadId, $accountId),
                'available_at' => $accountId === '' ? null : $this->reactivation->availableAt($accountId)?->toIso8601String(),
            ],
        ]);
    }

    private function email(mixed $email): ?string
    {
        $email = trim((string) $email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? null : mb_strtolower($email);
    }

    private function loginError(LoginException $e): JsonResponse
    {
        return $this->error($e->reason, $e->getMessage(), $e->status, [
            'attempts_left' => $e->attemptsLeft,
            'until' => $e->until?->toIso8601String(),
        ]);
    }

    /** @param array<string, mixed> $extra */
    private function error(string $code, string $message, int $status, array $extra = []): JsonResponse
    {
        return response()->json(['code' => $code, 'message' => $message] + array_filter($extra, fn ($v) => $v !== null), $status);
    }
}
