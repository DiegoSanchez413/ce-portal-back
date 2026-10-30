<?php

declare(strict_types=1);

use App\Modules\ClientLogs\ClientLogController;
use Illuminate\Support\Facades\Route;

Route::post('client-logs', ClientLogController::class)->middleware('throttle:client-logs');
