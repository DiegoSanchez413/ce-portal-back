<?php

declare(strict_types=1);

use App\Modules\ClientPortal\Http\Controllers\AccessController;
use App\Modules\ClientPortal\Http\Controllers\CardsController;
use App\Modules\ClientPortal\Http\Controllers\CreditController;
use App\Modules\ClientPortal\Http\Controllers\EventController;
use App\Modules\ClientPortal\Http\Controllers\FilesController;
use App\Modules\ClientPortal\Http\Controllers\HomeController;
use App\Modules\ClientPortal\Http\Controllers\LettersController;
use App\Modules\ClientPortal\Http\Controllers\LoginController;
use App\Modules\ClientPortal\Http\Controllers\PayController;
use App\Modules\ClientPortal\Http\Controllers\PaymentsController;
use App\Modules\ClientPortal\Http\Controllers\ProfileController;
use App\Modules\ClientPortal\Http\Controllers\SecurityController;
use App\Modules\ClientPortal\Http\Controllers\SessionController;
use App\Modules\ClientPortal\Http\Middleware\AssignPortalDevice;
use App\Modules\ClientPortal\Http\Middleware\AuthenticatePortalSession;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->middleware(AssignPortalDevice::class)->controller(LoginController::class)->group(function (): void {
    Route::post('code', 'requestCode')->middleware('throttle:portal-code');
    Route::post('resend', 'resend')->middleware('throttle:portal-code');
    Route::post('verify', 'verify')->middleware('throttle:portal-verify');
    Route::post('select-client', 'selectClient')->middleware('throttle:portal-verify');
    Route::post('reactivation', 'reactivation')->middleware('throttle:portal-verify');
});

Route::middleware([AssignPortalDevice::class, AuthenticatePortalSession::class])->group(function (): void {
    Route::controller(SessionController::class)->prefix('session')->group(function (): void {
        Route::get('/', 'show');
        Route::get('clients', 'clients');
        Route::post('switch-client', 'switchClient');
        Route::post('switch-account', 'switchAccount');
        Route::post('language', 'language');
        Route::post('logout', 'logout');
    });

    Route::post('events/section-view', [EventController::class, 'sectionView']);

    Route::get('home', [HomeController::class, 'show']);
    Route::post('home/services-interest', [HomeController::class, 'servicesInterest'])->middleware('throttle:portal-requests');
    Route::get('credit-reports', [CreditController::class, 'index']);
    Route::post('credit-reports/{id}/download', [CreditController::class, 'download'])->whereNumber('id');
    Route::get('payments', [PaymentsController::class, 'index']);
    Route::post('payments/{transactionId}/receipt', [PaymentsController::class, 'receipt']);
    Route::get('profile', [ProfileController::class, 'show']);
    Route::post('profile/change-request', [ProfileController::class, 'changeRequest'])->middleware('throttle:portal-requests');
    Route::get('access/devices', [AccessController::class, 'index']);
    Route::post('access/devices/{key}/sign-out', [AccessController::class, 'signOutDevice'])->where('key', '[a-f0-9-]{36,40}');
    Route::post('access/sign-out-others', [AccessController::class, 'signOutOthers']);

    Route::post('security/code', [SecurityController::class, 'send'])->middleware('throttle:portal-code');
    Route::post('security/verify', [SecurityController::class, 'verify'])->middleware('throttle:portal-verify');
    Route::get('cards', [CardsController::class, 'index']);
    Route::post('cards', [CardsController::class, 'store'])->middleware('throttle:portal-requests');
    Route::post('cards/scan', [CardsController::class, 'scan'])->middleware('throttle:portal-requests');
    Route::post('cards/{id}/default', [CardsController::class, 'setDefault'])->whereNumber('id');
    Route::get('pay', [PayController::class, 'show']);
    Route::post('pay', [PayController::class, 'pay'])->middleware('throttle:portal-verify');

    Route::get('files', [FilesController::class, 'index']);
    Route::post('files/{type}/view', [FilesController::class, 'view'])->whereIn('type', ['id', 'utility_bill', 'ssn']);
    Route::post('files/{type}', [FilesController::class, 'upload'])->whereIn('type', ['id', 'utility_bill', 'ssn'])->middleware('throttle:portal-requests');
    Route::get('letters', [LettersController::class, 'index']);
    Route::post('letters', [LettersController::class, 'store'])->middleware('throttle:portal-requests');
});
