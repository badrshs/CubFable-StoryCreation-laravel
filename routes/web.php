<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BookDownloadController;
use App\Http\Controllers\CharacterController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\DebugPromptController;
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
    Route::get('books/{id}/edit', [BookController::class, 'edit'])->whereNumber('id')->name('books.edit');
    Route::patch('books/{id}', [BookController::class, 'update'])->whereNumber('id')->name('books.update');
    Route::delete('books/{id}', [BookController::class, 'destroy'])->whereNumber('id')->name('books.destroy');
    Route::post('books/{id}/regenerate-cover', [BookController::class, 'regenerateCover'])->whereNumber('id')->name('books.regenerate-cover');
    Route::post('books/{id}/restyle', [BookController::class, 'restyle'])->whereNumber('id')->name('books.restyle');
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

    // Dev-only prompt inspection (404s in production).
    Route::get('_debug/books/{id}/prompts', DebugPromptController::class)->whereNumber('id')->name('debug.book-prompts');
});

Route::prefix('admin')->middleware(['auth', 'admin'])->group(function () {
    Route::get('', Admin\DashboardController::class)->name('admin.dashboard');
    Route::get('settings', [Admin\SettingController::class, 'index'])->name('admin.settings');
    Route::put('settings', [Admin\SettingController::class, 'update'])->name('admin.settings.update');
    Route::get('settings/pdf-preview', [Admin\SettingController::class, 'pdfPreview'])->name('admin.settings.pdf-preview');

    Route::get('books', [Admin\BookController::class, 'index'])->name('admin.books');
    Route::get('books/{id}', [Admin\BookController::class, 'show'])->whereNumber('id')->name('admin.books.show');
    Route::post('books/{id}/resume', [Admin\BookController::class, 'resume'])->whereNumber('id')->name('admin.books.resume');
    Route::post('books/{id}/restart', [Admin\BookController::class, 'restart'])->whereNumber('id')->name('admin.books.restart');
    Route::post('books/{id}/stop', [Admin\BookController::class, 'stop'])->whereNumber('id')->name('admin.books.stop');
    Route::post('books/{id}/images/regenerate', [Admin\BookController::class, 'regenerateImage'])->whereNumber('id')->name('admin.books.images.regenerate');
    Route::post('books/{id}/images/restore', [Admin\BookController::class, 'restoreImage'])->whereNumber('id')->name('admin.books.images.restore');
    Route::get('books/{id}/log', [Admin\BookController::class, 'log'])->whereNumber('id')->name('admin.books.log');
    Route::post('books/{id}/heal', [Admin\BookController::class, 'heal'])->whereNumber('id')->name('admin.books.heal');
    Route::post('books/{id}/restyle', [Admin\BookController::class, 'restyle'])->whereNumber('id')->name('admin.books.restyle');
    Route::delete('books/{id}', [Admin\BookController::class, 'destroy'])->whereNumber('id')->name('admin.books.destroy');

    Route::get('templates', [Admin\TemplateController::class, 'index'])->name('admin.templates');
    Route::get('templates/create', [Admin\TemplateController::class, 'create'])->name('admin.templates.create');
    Route::post('templates', [Admin\TemplateController::class, 'store'])->name('admin.templates.store');
    Route::get('templates/{id}/edit', [Admin\TemplateController::class, 'edit'])->whereNumber('id')->name('admin.templates.edit');
    Route::put('templates/{id}', [Admin\TemplateController::class, 'update'])->whereNumber('id')->name('admin.templates.update');
    Route::delete('templates/{id}', [Admin\TemplateController::class, 'destroy'])->whereNumber('id')->name('admin.templates.destroy');

    Route::get('logs', [Admin\LogController::class, 'index'])->name('admin.logs');
    Route::get('logs/download', [Admin\LogController::class, 'download'])->name('admin.logs.download');
    Route::delete('logs/all', [Admin\LogController::class, 'clearAll'])->name('admin.logs.clear-all');
    Route::delete('logs', [Admin\LogController::class, 'clear'])->name('admin.logs.clear');

    Route::get('playground', [Admin\PlaygroundController::class, 'index'])->name('admin.playground');
    Route::post('playground/preview', [Admin\PlaygroundController::class, 'preview'])->name('admin.playground.preview');
    Route::post('playground/run-text', [Admin\PlaygroundController::class, 'runText'])->name('admin.playground.run-text');
    Route::post('playground/run-image', [Admin\PlaygroundController::class, 'runImage'])->name('admin.playground.run-image');
});

Route::post('webhooks/stripe', StripeWebhookController::class)->name('webhooks.stripe');

Route::redirect('dashboard', '/books')->name('dashboard');

require __DIR__.'/settings.php';

Route::fallback(fn () => Inertia::render('not-found'));
