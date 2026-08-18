<?php

namespace App\Services\Delivery\FreeShipping;

/**
 * Контекст оценки бесплатной доставки (см. docs/tasks/free-shipping.md).
 *
 * Собирается из корзины (публичный endpoint витрины) либо из готового заказа
 * (чекаут). Один и тот же контекст используют и матчинг правил, и подсказка
 * «до бесплатной доставки осталось N ₽».
 */
final class FreeShippingContext
{
    /**
     * @param  array<int, array{product_id:int, quantity:int, price:float, is_gift?:bool}>  $items
     *         Позиции с ФИНАЛЬНЫМИ ценами (после товарных скидок, промокода
     *         и скидки по акции). Подарки (is_gift = true) в сумму не идут.
     * @param  string|null  $service  'cdek' | 'yandex' | null (ещё не выбрана)
     * @param  string|null  $deliveryType  'pickup' | 'courier' | null
     * @param  string|null  $paymentMethod  код способа оплаты
     */
    public function __construct(
        public array $items = [],
        public ?string $service = null,
        public ?string $deliveryType = null,
        public ?string $paymentMethod = null,
        public ?int $countryId = null,
        public ?int $regionId = null,
        public ?int $cityId = null,
        public ?string $countryName = null,
        public ?string $regionName = null,
        public ?string $cityName = null,
    ) {}

    /**
     * Копия контекста с другой службой/видом доставки — для перебора
     * вариантов доставки, показанных покупателю.
     */
    public function withDelivery(?string $service, ?string $deliveryType): self
    {
        $clone = clone $this;
        $clone->service = $service;
        $clone->deliveryType = $deliveryType;

        return $clone;
    }
}
