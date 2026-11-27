<?php

return [
    // Провайдер по умолчанию
    'default' => env('DEFAULT_PAYMENT_PROVIDER', 'yookassa'),

    // Настройки провайдеров
    // Ставка НДС (%) для позиций без ставки из МойСклад и для доставки.
    'receipt_default_vat' => (int) env('RECEIPT_DEFAULT_VAT', 5),

    'providers' => [
        'yookassa' => [
            'class' => \App\Services\Payment\YookassaProvider::class,
            'shop_id' => env('YOOKASSA_SHOP_ID'),
            'secret_key' => env('YOOKASSA_SECRET_KEY'),
            'send_receipt' => env('YOOKASSA_SEND_RECEIPT', false),
            'vat_code' => env('YOOKASSA_VAT_CODE', '7'),
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
            'receipt_tax' => env('YANDEX_PAY_RECEIPT_TAX', 8),
        ],

        'cloudpayment' => [
            'class' => \App\Services\Payment\CloudPaymentProvider::class,
            'enabled' => env('CLOUDPAYMENTS_ENABLED', false),
            'public_id' => env('CLOUDPAYMENT_PUBLIC_ID'),
            'api_secret' => env('CLOUDPAYMENT_API_SECRET'),
            'api_url' => env('CLOUDPAYMENT_API_URL', 'https://api.cloudpayments.ru'),
            // Ставка НДС для позиций CloudKassir. По умолчанию совпадает с
            // текущей настройкой HelixMedia; при другой ставке задать в .env.
            'receipt_vat' => (int) env('CLOUDPAYMENTS_RECEIPT_VAT', 5),
        ],

        // Robokassa: SberPay, иностранные карты, рассрочка Подели (способ
        // выбирает покупатель на странице Robokassa). См. docs/integrations/robokassa.md.
        // Пароли живут только в .env сервера. В тестовом режиме (IsTest=1)
        // Robokassa подписывает запросы отдельными тестовыми паролями.
        'robokassa' => [
            'class' => \App\Services\Payment\RobokassaProvider::class,
            'enabled' => (bool) env('ROBOKASSA_ENABLED', false),
            // Закрытый тест: пока список не пуст, способ оплаты доступен только клиентам
            // с этими email (через запятую). Пустой список — доступен всем.
            'allowed_emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('ROBOKASSA_ALLOWED_EMAILS', ''))))),
            'merchant_login' => env('ROBOKASSA_LOGIN'),
            'password1' => env('ROBOKASSA_PASSWORD1'),
            'password2' => env('ROBOKASSA_PASSWORD2'),
            // Password3 — ключ API возвратов; генерируется в кабинете Robokassa («Технические настройки»).
            'password3' => env('ROBOKASSA_PASSWORD3'),
            'refund_url' => env('ROBOKASSA_REFUND_URL', 'https://services.robokassa.ru/RefundService/Refund/Create'),
            'refund_state_url' => env('ROBOKASSA_REFUND_STATE_URL', 'https://services.robokassa.ru/RefundService/Refund/GetState'),
            // OpStateExt отдаёт OpKey — номер операции, по которому делается возврат.
            'op_state_ext_url' => env('ROBOKASSA_OPSTATE_EXT_URL', 'https://auth.robokassa.ru/Merchant/WebService/Service.asmx/OpStateExt'),
            'test_password1' => env('ROBOKASSA_TEST_PASSWORD1'),
            'test_password2' => env('ROBOKASSA_TEST_PASSWORD2'),
            'is_test' => (bool) env('ROBOKASSA_TEST_MODE', false),
            // Алгоритм подписи — как в «Технических настройках» магазина.
            'hash_algo' => env('ROBOKASSA_HASH_ALGO', 'md5'),
            'payment_url' => env('ROBOKASSA_PAYMENT_URL', 'https://auth.robokassa.ru/Merchant/Index.aspx'),
            // Чек 54-ФЗ передаём в Robokassa; ставка НДС (%) для позиций без своей.
            // Способы в окне оплаты (алиасы Robokassa). BankCard включает и иностранные карты.
            // Пустое значение — окно показывает всё, что подключено магазину.
            'payment_methods' => array_values(array_filter(array_map('trim', explode(',', (string) env('ROBOKASSA_PAYMENT_METHODS', 'BankCard,SberPay,Podeli'))))),
            'send_receipt' => (bool) env('ROBOKASSA_SEND_RECEIPT', true),
            'receipt_vat' => (int) env('ROBOKASSA_RECEIPT_VAT', 5),
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
                'vat_type' => env('HELIXMEDIA_VAT_TYPE', 'vat5'),
            ],
        ],
    ],
];
