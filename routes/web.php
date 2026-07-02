<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BookDownloadController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', HomeController::class)->name('home');

Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');

Route::middleware(['auth'])->group(function () {
    Route::get('create/{template}', [BookController::class, 'create'])->name('books.create');

    Route::get('books', [BookController::class, 'index'])->name('books.index');
    Route::post('books', [BookController::class, 'store'])->name('books.store');
    Route::get('books/{id}', [BookController::class, 'show'])->whereNumber('id')->name('books.show');
    Route::post('books/{id}/regenerate-cover', [BookController::class, 'regenerateCover'])->whereNumber('id')->name('books.regenerate-cover');
    Route::get('books/{id}/download', BookDownloadController::class)->whereNumber('id')->name('books.download');

    Route::patch('books/{id}/pages/{pageId}', [PageController::class, 'update'])->whereNumber('id')->whereNumber('pageId')->name('pages.update');
    Route::post('books/{id}/pages/{pageId}/regenerate', [PageController::class, 'regenerate'])->whereNumber('id')->whereNumber('pageId')->name('pages.regenerate');

    Route::get('checkout/{id}', [CheckoutController::class, 'show'])->whereNumber('id')->name('checkout.show');
    Route::post('books/{id}/reconcile', [CheckoutController::class, 'reconcile'])->whereNumber('id')->name('checkout.reconcile');

    Route::get('library', [CharacterController::class, 'index'])->name('characters.index');
    Route::post('characters', [CharacterController::class, 'store'])->name('characters.store');
    Route::patch('characters/{id}', [CharacterController::class, 'update'])->whereNumber('id')->name('characters.update');
    Route::delete('characters/{id}', [CharacterController::class, 'destroy'])->whereNumber('id')->name('characters.destroy');

    Route::get('account', AccountController::class)->name('account');
});

Route::post('webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');

Route::redirect('dashboard', '/books')->name('dashboard');

require __DIR__.'/settings.php';

Route::fallback(fn () => Inertia::render('not-found'));
