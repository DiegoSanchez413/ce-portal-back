<?php

declare(strict_types=1);

use App\Modules\Status\StatusController;
use Illuminate\Support\Facades\Route;

Route::get('status', StatusController::class);
