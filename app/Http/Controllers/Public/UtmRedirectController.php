<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\UtmLink;
use App\Models\UtmVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;

class UtmRedirectController extends Controller
{
    /**
     * Редирект-трекер: GET /go/{slug}.
     *
     * 1. Находит активную метку по slug (иначе 404).
     * 2. Пишет посещение в utm_visits.
     * 3. Ставит куку utm_link_id (окно атрибуции, настройки — config/utm.php).
     * 4. 302-redirect на целевой URL с utm-параметрами.
     */
    public function handle(Request $request, string $slug): RedirectResponse
    {
        $link = UtmLink::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $ip = $request->ip();
        $userAgent = $request->userAgent();

        UtmVisit::create([
            'utm_link_id' => $link->id,
            'visited_at' => now(),
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'referrer' => $request->headers->get('referer'),
            // Хэш для подсчёта уникальных посещений (решение #4).
            'visitor_hash' => hash('sha256', $ip.'|'.$userAgent),
        ]);

        $cfg = config('utm.attribution');

        // Кука атрибуции. domain/sameSite/secure берём из конфига, чтобы
        // поддержать кросс-доменную схему (витрина и API на разных origin).
        $cookie = Cookie::make(
            'utm_link_id',
            (string) $link->id,
            (int) $cfg['cookie_minutes'],
            null,                              // path
            $cfg['cookie_domain'] ?: null,     // domain
            (bool) $cfg['cookie_secure'],      // secure
            true,                              // httpOnly
            false,                             // raw
            $cfg['cookie_same_site'] ?: null   // sameSite
        );

        return redirect()->away($link->target_url_with_params)->withCookie($cookie);
    }
}
