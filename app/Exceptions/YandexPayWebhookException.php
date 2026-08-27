<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Ошибка обработки уведомления Яндекс Пэй.
 *
 * Яндекс Пэй ждёт в ответе документированный `reasonCode` и повторяет
 * доставку при любом статусе кроме 200 в течение 24 часов. Поэтому причина
 * отказа переносится в исключение и отдаётся контроллером как есть.
 *
 * @see https://pay.yandex.ru/docs/ru/custom/backend/merchant-api/webhook
 */
class YandexPayWebhookException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message,
        public readonly int $status = 400,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
