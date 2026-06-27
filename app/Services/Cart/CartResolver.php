<?php

namespace App\Services\Cart;

use App\Models\Cart;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Единая точка разрешения корзины посетителя (см. docs/tasks/universal-cart.md).
 *
 * Контроллеры НЕ должны содержать ветвление guest/auth — всю идентификацию
 * (клиент по Sanctum-токену или гость по HttpOnly-cookie guest_token) выполняет
 * этот сервис.
 */
class CartResolver
{
    /**
     * Вернуть активную корзину текущего посетителя, создавая её при отсутствии.
     * Для гостя при создании ставит HttpOnly cookie guest_token.
     * Вызывать на изменяющих операциях (добавление товара).
     */
    public function resolveOrCreate(Request $request): Cart
    {
        $client = $this->currentClient();

        if ($client) {
            $cart = Cart::firstOrCreate(
                ['client_id' => $client->id, 'status' => 'active'],
                ['created_at' => now()]
            );

            $this->touch($cart, $request);

            return $cart;
        }

        $token = $this->readGuestToken($request);

        $cart = $token
            ? Cart::where('guest_token', $token)->where('status', 'active')->first()
            : null;

        if (! $cart) {
            $token = (string) Str::uuid();
            $cart = Cart::create([
                'guest_token' => $token,
                'status' => 'active',
                'created_at' => now(),
            ]);

            $this->queueGuestCookie($token);
        }

        $this->touch($cart, $request);

        return $cart;
    }

    /**
     * Вернуть активную корзину текущего посетителя БЕЗ создания.
     * Вызывать на чтениях/удалениях (GET, удаление позиции, отмена).
     */
    public function resolveActive(Request $request): ?Cart
    {
        $client = $this->currentClient();

        if ($client) {
            $cart = Cart::where('client_id', $client->id)->where('status', 'active')->first();
        } else {
            $token = $this->readGuestToken($request);
            $cart = $token
                ? Cart::where('guest_token', $token)->where('status', 'active')->first()
                : null;
        }

        if ($cart) {
            $this->touch($cart, $request);
        }

        return $cart;
    }

    /**
     * Текущий авторизованный клиент (только Client, не админ-User).
     */
    public function currentClient(): ?Client
    {
        $user = auth('sanctum')->user();

        return $user instanceof Client ? $user : null;
    }

    /**
     * Прочитать guest_token из cookie (plaintext — имя в encryptCookies except).
     */
    public function readGuestToken(Request $request): ?string
    {
        $value = $request->cookie(config('cart.cookie.name', 'guest_token'));

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Поставить HttpOnly cookie guest_token в очередь ответа.
     * AddQueuedCookiesToResponse (подключён к api-группе) прикрепит её к ответу.
     */
    protected function queueGuestCookie(string $token): void
    {
        Cookie::queue(cookie(
            name: config('cart.cookie.name', 'guest_token'),
            value: $token,
            minutes: (int) config('cart.cookie.days', 365) * 24 * 60,
            path: config('cart.cookie.path', '/'),
            domain: config('cart.cookie.domain'),
            secure: (bool) config('cart.cookie.secure', true),
            httpOnly: true,
            raw: false,
            sameSite: config('cart.cookie.same_site', 'none'),
        ));
    }

    /**
     * Зафиксировать активность корзины. updated_at не трогаем — он отражает
     * изменение состава и бампается в путях upsert/remove.
     */
    protected function touch(Cart $cart, Request $request): void
    {
        $attributes = ['last_activity_at' => now()];

        // Для гостя фиксируем UA/IP один раз (аналитика / фильтр ботов).
        if (! $cart->client_id) {
            if (! $cart->user_agent) {
                $attributes['user_agent'] = substr((string) $request->userAgent(), 0, 255);
            }
            if (! $cart->ip_address) {
                $attributes['ip_address'] = $request->ip();
            }
        }

        $cart->forceFill($attributes)->save();
    }
}
