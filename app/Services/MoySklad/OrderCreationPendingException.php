<?php

namespace App\Services\MoySklad;

use Exception;

/**
 * Заказ уже отправлен на создание в МойСклад, но ответа не было, и документ ещё не виден.
 * Создавать второй нельзя — нужно повторить выгрузку позже.
 */
class OrderCreationPendingException extends Exception
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('МойСклад: заказ ещё может создаваться по прошлому запросу, повторим позже.');
    }
}
