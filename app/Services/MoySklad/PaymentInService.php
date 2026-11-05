<?php

namespace App\Services\MoySklad;

use App\Models\DeliveryServiceSetting;
use App\Models\Order;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Передаёт оплату заказа в МойСклад документом «Входящий платёж», связанным с заказом
 * покупателя. После этого у заказа в МойСклад заполняется «Оплачено», и автоматика
 * аккаунта не считает его неоплаченным.
 */
class PaymentInService
{
    private string $baseURL = 'https://api.moysklad.ru/api/remap/1.2';

    private string $token;

    public function __construct(?string $token = null)
    {
        if ($token === null) {
            $settings = DeliveryServiceSetting::where('service_name', 'moysklad')->first();

            if (! $settings) {
                throw new Exception('Настройки для МойСклад не найдены. Пожалуйста, настройте сервис в админке.');
            }

            $token = $settings->token;
        }

        $this->token = $token;
    }

    /**
     * Создать входящий платёж по оплаченному заказу (идемпотентно).
     *
     * @return string|null UUID платежа или null, если платить нечего
     *
     * @throws Exception
     */
    public function registerPayment(Order $order): ?string
    {
        if ($order->moysklad_paymentin_uuid) {
            return $order->moysklad_paymentin_uuid;
        }

        if (! $order->isPaid() || ! $order->moysklad_order_uuid) {
            return null;
        }

        $sum = self::toKopecks($order->total_amount);
        if ($sum <= 0) {
            return null;
        }

        $msOrder = $this->request()->get("{$this->baseURL}/entity/customerorder/{$order->moysklad_order_uuid}");
        if (! $msOrder->successful()) {
            throw new Exception('МойСклад: не удалось получить заказ для входящего платежа. ' . ($msOrder->json('errors.0.error') ?? $msOrder->body()));
        }

        $payload = self::buildPayload(
            $order,
            $sum,
            (int) $msOrder->json('sum'),
            (new OrderService())->getOrganizationMeta(),
            (new CounterpartyService())->findOrCreateMeta($order),
            $this->baseURL,
        );

        $response = $this->request()->post("{$this->baseURL}/entity/paymentin", $payload);

        if (! $response->successful()) {
            Log::error('MoySklad: ошибка создания входящего платежа', [
                'order_id' => $order->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new Exception('МойСклад: не удалось создать входящий платёж. ' . ($response->json('errors.0.error') ?? $response->body()));
        }

        $uuid = $response->json('id');
        $order->forceFill(['moysklad_paymentin_uuid' => $uuid])->save();

        Log::info('MoySklad: входящий платёж создан', ['order_id' => $order->id, 'paymentin_uuid' => $uuid]);

        return $uuid;
    }

    /**
     * Тело запроса. linkedSum не может превышать сумму заказа в МойСклад (в неё может
     * не входить доставка), поэтому ограничиваем им связанную часть платежа.
     */
    public static function buildPayload(Order $order, int $sum, int $msOrderSum, array $organizationMeta, array $agentMeta, string $baseURL): array
    {
        $linked = $msOrderSum > 0 ? min($sum, $msOrderSum) : $sum;

        return [
            'organization' => ['meta' => $organizationMeta],
            'agent' => ['meta' => $agentMeta],
            'sum' => $sum,
            'externalCode' => 'pay-' . $order->id,
            'paymentPurpose' => 'Оплата заказа № ' . $order->order_number,
            'operations' => [[
                'meta' => [
                    'href' => "{$baseURL}/entity/customerorder/{$order->moysklad_order_uuid}",
                    'type' => 'customerorder',
                    'mediaType' => 'application/json',
                ],
                'linkedSum' => $linked,
            ]],
        ];
    }

    public static function toKopecks(float|string|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function request()
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
            'Accept-Encoding' => 'gzip',
            'Content-Type' => 'application/json',
        ]);
    }
}
