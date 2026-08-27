<?php

/**
 * Справочники для правил бесплатной доставки (docs/tasks/free-shipping.md).
 *
 * Единый источник правды и для валидации admin-CRUD, и для endpoint'а
 * `/api/free-shipping-rules/options`, из которого дашборд наполняет селекты.
 */
return [
    // Службы доставки, доступные на витрине. Legacy-методы (Boxberry, Почта
    // России) сюда не входят: покупателю они не предлагаются.
    'services' => [
        'cdek' => 'СДЭК',
        'yandex' => 'Яндекс.Доставка',
    ],

    // Вид доставки. Постамат считается разновидностью ПВЗ.
    'delivery_types' => [
        'pickup' => 'Пункт выдачи (ПВЗ)',
        'courier' => 'Курьерская доставка',
    ],

    // Канонические коды способов оплаты витрины
    // (совпадают с again_front/constants/payment.ts).
    'payment_methods' => [
        'card_ru' => 'Банковская карта',
        'cloudpayments_tpay' => 'T-Pay',
        'cloudpayments_sbp' => 'СБП',
        'cloudpayments_sberpay' => 'SberPay',
        'cloudpayments_mirpay' => 'Mir Pay',
        'yandex_pay' => 'Яндекс Пэй',
        'yandex_pay_split' => 'Яндекс Сплит',
    ],

    // География, в которой оформляется чек. Только эти страны доступны для
    // условий бесплатной доставки в админке.
    'country_codes' => ['RU', 'AM', 'BY', 'KZ', 'KG'],

    // Соответствие «код способа доставки → служба/вид». Используется, когда
    // в заказе нет delivery_data (например, админский заказ без интеграции).
    'method_code_map' => [
        'cdek_pickup' => ['service' => 'cdek', 'delivery_type' => 'pickup'],
        'cdek_postamat' => ['service' => 'cdek', 'delivery_type' => 'pickup'],
        'cdek_courier' => ['service' => 'cdek', 'delivery_type' => 'courier'],
        'yandex_pickup' => ['service' => 'yandex', 'delivery_type' => 'pickup'],
        'yandex_courier' => ['service' => 'yandex', 'delivery_type' => 'courier'],
    ],
];
