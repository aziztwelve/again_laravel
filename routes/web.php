<?php

use App\Http\Controllers\Public\UtmRedirectController;
use DefStudio\Telegraph\Controllers\WebhookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\YandexPayWebhookController;

// Яндекс Пэй добавляет /v1/webhook к Callback URL из кабинета.
Route::post('/v1/webhook', YandexPayWebhookController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('yandex-pay.webhook');

Route::post('telegraph/{token}/webhook', [WebhookController::class, 'handle'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('telegraph.webhook');

Route::get('telegraph/{token}/webhook', function ($token) {
    return response()->json(['status' => 'ok'], 200);
});

// UTM редирект-трекер: ставит куку атрибуции + 302 на целевую страницу.
// Throttle защищает от накрутки посещений. См. docs/tasks/utm-tracking.md.
Route::get('/go/{slug}', [UtmRedirectController::class, 'handle'])
    ->where('slug', '[A-Za-z0-9]+')
    ->middleware('throttle:60,1')
    ->name('utm.go');
