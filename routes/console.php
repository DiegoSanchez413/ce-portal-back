<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('client-portal:purge-activity')->monthlyOn(1, '03:00');
