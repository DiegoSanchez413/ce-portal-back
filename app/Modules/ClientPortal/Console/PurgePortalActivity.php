<?php

declare(strict_types=1);

namespace App\Modules\ClientPortal\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('client-portal:purge-activity')]
#[Description('Delete client portal sessions, events and login data older than 12 months')]
final class PurgePortalActivity extends Command
{
    public function handle(): int
    {
        $limit = now()->subMonths(12);

        $events = DB::table('client_portal_events')->where('created_at', '<', $limit)->delete();
        $sessions = DB::table('client_portal_sessions')->where('started_at', '<', $limit)->delete();
        DB::table('client_portal_otps')->where('created_at', '<', $limit)->delete();
        DB::table('client_portal_challenges')->where('created_at', '<', $limit)->delete();

        $this->info("Deleted {$events} events and {$sessions} sessions.");

        return self::SUCCESS;
    }
}
