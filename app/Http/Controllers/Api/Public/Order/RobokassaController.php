<?php

namespace App\Http\Controllers\Api\Public\Order;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Order;
use App\Services\Payment\RobokassaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Оплата через Robokassa (SberPay, иностранная карта, рассрочка Подели).
 * См. docs/integrations/robokassa.md.
 */
class RobokassaController extends Controller
{
    /**
     * Какие дополнительные способы оплаты показать в чекауте текущему покупателю.
     * Маршрут публичный: Bearer-токен необязателен (гость получает false, пока
     * способ ограничен списком email).
     */
    public function checkoutOptions(RobokassaService $service): JsonResponse
    {
        $user = Auth::guard('sanctum')->user();

        return response()->json([
            'success' => true,
            'robokassa' => $service->isOfferedTo($user instanceof Client ? $user : null),
        ]);
    }

    /** Поля формы оплаты для заказа по viewToken. Сумму считает сервер. */
    public function intent(string $viewToken, RobokassaService $service): JsonResponse
    {
        if (! $service->isAvailable()) {
            return response()->json(['success' => false, 'message' => 'Онлайн-оплата временно недоступна.'], 503);
        }

        $order = Order::query()->with(['client'])->where('view_token', $viewToken)->first();
        if (! $order || ! $service->supports($order->payment_method) || ! $service->isAllowedForOrder($order) || ! $order->canBePaid()) {
            return response()->json(['success' => false, 'message' => 'Для этого заказа недоступна онлайн-оплата.'], 422);
        }

        try {
            return response()->json(['success' => true, ...$service->intent($order)]);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['success' => false, 'message' => 'Не удалось подготовить оплату. Попробуйте ещё раз.'], 500);
        }
    }

    /**
     * Result URL: серверное уведомление об оплате.
     * Ответ `OK{InvId}` — подтверждение; любой другой — Robokassa повторит запрос.
     */
    public function result(Request $request, RobokassaService $service): Response
    {
        $data = $request->all();

        if (! $service->isValidResultSignature($data)) {
            Log::warning('Robokassa result: неверная подпись', ['inv_id' => $data['InvId'] ?? null]);

            return response('bad sign', 400)->header('Content-Type', 'text/plain');
        }

        try {
            $service->confirmPayment($data);
        } catch (RuntimeException $exception) {
            Log::warning($exception->getMessage());

            return response('bad payment', 400)->header('Content-Type', 'text/plain');
        }

        return response('OK' . (int) $data['InvId'], 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Success URL: возвращает покупателя на страницу заказа (из окна оплаты —
     * на верхнем уровне). Оплату не подтверждает — это делает Result URL.
     */
    public function success(Request $request, RobokassaService $service): Response
    {
        $data = $request->all();

        if (! $service->isValidSuccessSignature($data)) {
            return $this->toShop();
        }

        return $this->toOrder($service, $data, 'success');
    }

    /**
     * Fail URL: подписи у редиректа нет, а ссылка на заказ — секрет (viewToken).
     * Поэтому на страницу заказа ведём только при верном Shp_pay, который мы
     * сами подписали при создании платежа; иначе — на главную.
     */
    public function fail(Request $request, RobokassaService $service): Response
    {
        $data = $request->all();

        if (! $service->isValidShpToken($data)) {
            return $this->toShop();
        }

        return $this->toOrder($service, $data, 'fail');
    }

    private function toOrder(RobokassaService $service, array $data, string $result): Response
    {
        $order = $service->findPayment($data)?->order;
        if (! $order) {
            return $this->toShop();
        }

        return $this->leave($this->shopUrl("/orders/{$order->view_token}?payment={$result}"));
    }

    private function toShop(): Response
    {
        return $this->leave($this->shopUrl('/'));
    }

    /**
     * Оплата открывается в iframe Robokassa, и Success/Fail могут загрузиться
     * внутри окна. Обычный редирект показал бы магазин внутри модального окна,
     * поэтому отдаём страницу, которая переводит на адрес окно верхнего уровня
     * (после перехода на наш домен он совпадает с адресом верхней страницы).
     * Если окна нет — это просто переход. Без JS работает meta refresh и ссылка.
     */
    private function leave(string $url): Response
    {
        $js = json_encode($url, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $attr = e($url);

        $html = '<!doctype html><html lang="ru"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Возврат в магазин</title>'
            . '<meta http-equiv="refresh" content="2;url=' . $attr . '"></head><body>'
            . '<script>(function(u){try{window.top.location.replace(u)}catch(e){window.location.replace(u)}})(' . $js . ');</script>'
            . '<p style="font-family:sans-serif;text-align:center;margin-top:3rem">'
            . '<a href="' . $attr . '" target="_top">Вернуться в магазин</a></p>'
            . '</body></html>';

        return response($html, 200)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function shopUrl(string $path): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . $path;
    }
}
