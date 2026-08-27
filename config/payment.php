<?php

return [
    // Провайдер по умолчанию
    'default' => env('DEFAULT_PAYMENT_PROVIDER', 'yookassa'),

    // Настройки провайдеров
    'providers' => [
        'yookassa' => [
            'class' => \App\Services\Payment\YookassaProvider::class,
            'shop_id' => env('YOOKASSA_SHOP_ID'),
            'secret_key' => env('YOOKASSA_SECRET_KEY'),
            'send_receipt' => env('YOOKASSA_SEND_RECEIPT', false),
            'vat_code' => env('YOOKASSA_VAT_CODE', '1'),
        ],

        // Яндекс Пэй / Сплит — базовая механика полной оплаты.
        // См. docs/tasks/yandex-pay-integration.md. Merchant API key живёт
        // только на сервере; во фронт уходит лишь merchant_id и env.
        'yandexpay' => [
            'enabled' => env('YANDEX_PAY_ENABLED', false),
            'env' => env('YANDEX_PAY_ENV', 'sandbox'),
            'merchant_id' => env('YANDEX_PAY_MERCHANT_ID'),
            // В sandbox ключ API равен Merchant ID (требование Яндекс Пэй).
            'api_key' => env('YANDEX_PAY_API_KEY'),
            'api_url' => env('YANDEX_PAY_API_URL', 'https://sandbox.pay.yandex.ru'),
            // В кабинете Callback URL задаётся без суффикса /v1/webhook —
            // Яндекс Пэй добавляет его сам. Здесь значение только для справки.
            'callback_url' => env('YANDEX_PAY_CALLBACK_URL'),
            // Время жизни ссылки на оплату (сек), 120..604800. По истечении
            // Яндекс Пэй переводит заказ в FAILED, локальная попытка ротируется.
            'order_ttl' => (int) env('YANDEX_PAY_ORDER_TTL', 1800),
            // Ставка НДС для cart.items[].receipt.tax. Заполнять только когда
            // в кабинете включена онлайн-касса и ставка согласована с
            // бухгалтерией: https://pay.yandex.ru/docs/ru/custom/backend/fns#tax
            'receipt_tax' => env('YANDEX_PAY_RECEIPT_TAX'),
        ],

        'cloudpayment' => [
            'class' => \App\Services\Payment\CloudPaymentProvider::class,
            'enabled' => env('CLOUDPAYMENTS_ENABLED', false),
            'public_id' => env('CLOUDPAYMENT_PUBLIC_ID'),
            'api_secret' => env('CLOUDPAYMENT_API_SECRET'),
            'api_url' => env('CLOUDPAYMENT_API_URL', 'https://api.cloudpayments.ru'),
        ],

        'robokassa' => [
            'class' => \App\Services\Payment\RobokassaProvider::class,
            'merchant_login' => env('ROBOKASSA_LOGIN'),
            'password1' => env('ROBOKASSA_PASSWORD1'),
            'password2' => env('ROBOKASSA_PASSWORD2'),
            'is_test' => env('ROBOKASSA_TEST_MODE', false),
            'payment_url' => env('ROBOKASSA_PAYMENT_URL', 'https://auth.robokassa.ru/Merchant/Index'),
            'status_url' => env('ROBOKASSA_STATUS_URL', 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx/OpState'),
            'refund_url' => env('ROBOKASSA_REFUND_URL', 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx/RefundPayment'),
        ],
    ],

    // Настройки чеков
    'receipts' => [
        'provider' => env('RECEIPT_PROVIDER', 'helixmedia'),

        'providers' => [
            'helixmedia' => [
                'class' => \App\Services\Receipt\HelixmediaReceiptService::class,
                'api_key' => env('HELIXMEDIA_API_KEY'),
                'api_url' => env('HELIXMEDIA_API_URL'),
                'vat_type' => env('HELIXMEDIA_VAT_TYPE', 'vat20'),
            ],
        ],
    ],
];
