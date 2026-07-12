<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\BookController;
use App\Http\Controllers\Api\V1\BookStatusController;
use App\Http\Controllers\Api\V1\CharacterController;
use App\Http\Controllers\Api\V1\IapController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\MetaController;
use App\Http\Controllers\Api\V1\PageController;
use App\Http\Controllers\Api\V1\PasswordController;
use App\Http\Controllers\Api\V1\RevenueCatWebhookController;
use App\Http\Controllers\Api\V1\TemplateController;
use App\Http\Controllers\BookDownloadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mobile API (v1)
|--------------------------------------------------------------------------
|
| Token-authenticated JSON API consumed by the CubFable mobile app. Web
| traffic keeps using the Inertia routes in routes/web.php; both clients
| share the same services, form requests, and payload shapes.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::middleware('throttle:api-auth')->group(function (): void {
        Route::post('auth/register', RegisterController::class)->name('auth.register');
        Route::post('auth/login', LoginController::class)->name('auth.login');
        Route::post('auth/forgot-password', ForgotPasswordController::class)->name('auth.forgot-password');
    });

    Route::middleware('throttle:api')->group(function (): void {
        Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
        Route::get('meta', MetaController::class)->name('meta');
    });

    Route::post('webhooks/revenuecat', RevenueCatWebhookController::class)
        ->middleware('throttle:60,1')
        ->name('webhooks.revenuecat');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function (): void {
        Route::post('auth/logout', LogoutController::class)->name('auth.logout');

        Route::get('me', [MeController::class, 'show'])->name('me.show');
        Route::patch('me', [MeController::class, 'update'])->name('me.update');
        Route::put('me/password', [PasswordController::class, 'update'])
            ->middleware('throttle:6,1')
            ->name('me.password');
        Route::post('email/verification-notification', EmailVerificationNotificationController::class)
            ->middleware('throttle:6,1')
            ->name('verification.send');
        Route::delete('account', [AccountController::class, 'destroy'])
            ->middleware('throttle:6,1')
            ->name('account.destroy');

        Route::get('books', [BookController::class, 'index'])->name('books.index');
        Route::post('books', [BookController::class, 'store'])->name('books.store');
        Route::get('books/{id}', [BookController::class, 'show'])->whereNumber('id')->name('books.show');
        Route::patch('books/{id}', [BookController::class, 'update'])->whereNumber('id')->name('books.update');
        Route::delete('books/{id}', [BookController::class, 'destroy'])->whereNumber('id')->name('books.destroy');
        Route::get('books/{id}/status', BookStatusController::class)->whereNumber('id')->name('books.status');
        Route::post('books/{id}/regenerate-cover', [BookController::class, 'regenerateCover'])
            ->whereNumber('id')
            ->name('books.regenerate-cover');
        Route::post('books/{id}/restyle', [BookController::class, 'restyle'])->whereNumber('id')->name('books.restyle');
        Route::get('books/{id}/download', BookDownloadController::class)->whereNumber('id')->name('books.download');
        Route::post('books/{id}/iap/intent', [IapController::class, 'intent'])
            ->whereNumber('id')
            ->name('books.iap.intent');
        Route::post('books/{id}/iap/reconcile', [IapController::class, 'reconcile'])
            ->whereNumber('id')
            ->name('books.iap.reconcile');

        Route::patch('books/{id}/pages/{pageId}', [PageController::class, 'update'])
            ->whereNumber('id')
            ->whereNumber('pageId')
            ->name('pages.update');
        Route::post('books/{id}/pages/{pageId}/regenerate', [PageController::class, 'regenerate'])
            ->whereNumber('id')
            ->whereNumber('pageId')
            ->name('pages.regenerate');

        Route::get('characters', [CharacterController::class, 'index'])->name('characters.index');
        Route::post('characters', [CharacterController::class, 'store'])->name('characters.store');
        Route::patch('characters/{id}', [CharacterController::class, 'update'])
            ->whereNumber('id')
            ->name('characters.update');
        Route::delete('characters/{id}', [CharacterController::class, 'destroy'])
            ->whereNumber('id')
            ->name('characters.destroy');
    });
});
