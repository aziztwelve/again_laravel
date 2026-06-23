<?php

use App\Http\Controllers\Public\UtmRedirectController;
use Illuminate\Support\Facades\Route;

Route::get('telegraph/{token}/webhook', function ($token) {
    return response()->json(['status' => 'ok'], 200);
});

// UTM редирект-трекер: ставит куку атрибуции + 302 на целевую страницу.
// Throttle защищает от накрутки посещений. См. docs/tasks/utm-tracking.md.
Route::get('/go/{slug}', [UtmRedirectController::class, 'handle'])
    ->where('slug', '[A-Za-z0-9]+')
    ->middleware('throttle:60,1')
    ->name('utm.go');
