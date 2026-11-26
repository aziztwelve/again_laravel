<?php

namespace App\Services\MoySklad;

use Exception;

/**
 * Документ (заказ или платёж) уже отправлен на создание в МойСклад, но ответа не было,
 * и он ещё не виден. Создавать второй нельзя — нужно повторить выгрузку позже.
 */
class CreationPendingException extends Exception
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('МойСклад: документ ещё может создаваться по прошлому запросу, повторим позже.');
    }
}
