<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChallengeStore
{
    private const TTL_MINUTES = 30;

    /** @param list<int> $leadIds */
    public function create(string $method, string $mobile, ?string $email, array $leadIds, ?string $accountId, string $language, ?string $ip): LoginChallenge
    {
        $id = (string) Str::uuid();

        DB::table('client_portal_challenges')->insert([
            'id' => $id,
            'login_method' => $method,
            'mobile' => $mobile,
            'email' => $email,
            'lead_ids' => json_encode($leadIds),
            'client_account_id' => $accountId,
            'language' => $language,
            'ip' => $ip,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'created_at' => now(),
        ]);

        return $this->findOrFail($id);
    }

    public function find(string $id): ?LoginChallenge
    {
        $row = DB::table('client_portal_challenges')->where('id', $id)->where('expires_at', '>', now())->first();

        return $row === null ? null : LoginChallenge::fromRow($row);
    }

    public function markVerified(LoginChallenge $challenge, string $channel): void
    {
        DB::table('client_portal_challenges')->where('id', $challenge->id)
            ->update(['verified_at' => now(), 'verified_channel' => $channel]);
    }

    public function close(LoginChallenge $challenge): void
    {
        DB::table('client_portal_challenges')->where('id', $challenge->id)->update(['expires_at' => now()]);
    }

    private function findOrFail(string $id): LoginChallenge
    {
        return $this->find($id) ?? throw new \LogicException('Challenge not stored.');
    }
}
