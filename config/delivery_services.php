<?php

/*
 * Актуальные службы доставки для фильтров списка заказов в админке.
 *
 * Ключ — стабильный идентификатор службы для фильтра. В `codes` перечислены
 * коды способов доставки из таблицы delivery_methods, которые относятся к
 * этой службе. Старые и служебные способы (Электронная почта, «Курьером»
 * без уточнения, «Доставка не требуется», устаревшие коды Почты России)
 * сюда не входят и в фильтре не показываются.
 */

return [
    'cdek_pickup' => [
        'label' => 'Пункт выдачи СДЭК',
        'codes' => ['cdek_pickup'],
    ],
    'cdek_postamat' => [
        'label' => 'Постамат СДЭК',
        'codes' => ['cdek_postamat'],
    ],
    'cdek_courier' => [
        'label' => 'СДЭК: Курьерская доставка',
        'codes' => ['cdek_courier'],
    ],
    'yandex_pickup' => [
        'label' => 'Пункт самовывоза Яндекс.Доставки',
        'codes' => ['yandex_pickup'],
    ],
    'yandex_courier' => [
        'label' => 'Курьером Яндекс.Доставки',
        'codes' => ['yandex_courier'],
    ],
    'russian_post_office' => [
        'label' => 'Почта РФ: в отделение',
        'codes' => ['russian_post_office', 'russian_post_notification'],
    ],
    'russian_post_courier' => [
        'label' => 'Почта РФ: Курьер',
        'codes' => ['russian_post_courier'],
    ],
    'international_pickup' => [
        'label' => 'Почта РФ: международная в пвз',
        'codes' => ['international_pickup'],
    ],
    'international_courier' => [
        'label' => 'Почта РФ: международная курьер',
        'codes' => ['international_courier'],
    ],
    'boxberry_courier' => [
        'label' => 'Курьером Boxberry',
        'codes' => ['boxberry_courier'],
    ],
    'boxberry_pickup' => [
        'label' => 'Пункт выдачи Boxberry',
        'codes' => ['boxberry_pickup'],
    ],
];
