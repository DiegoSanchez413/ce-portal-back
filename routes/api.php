<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Cada módulo declara sus rutas en app/Modules/<Modulo>/routes.php; acá se montan bajo /api/v1.
Route::prefix('v1')->group(function (): void {
    foreach (glob(app_path('Modules/*/routes.php')) ?: [] as $moduleRoutes) {
        require $moduleRoutes;
    }
});
