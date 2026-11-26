<?php

namespace App\Services\MoySklad;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Защита от повторного создания документа МойСклад, когда ответ на создание не дошёл.
 * МойСклад мог сохранить документ позже, поэтому некоторое время второй не создаём.
 * Используется вместе с поиском уже созданного документа перед запросом.
 */
trait GuardsDocumentCreation
{
    /** Сколько МойСклад может досохранять документ после запроса, на который сайт не получил ответа. */
    private const CREATE_PENDING_SECONDS = 180;

    /** Ожидание ответа на запись: под нагрузкой МойСклад отвечает дольше 30 секунд. */
    private const WRITE_TIMEOUT_SECONDS = 50;

    /**
     * Отметить начало создания. Отметка ставится ДО запроса: она переживёт и таймаут,
     * и остановку воркера.
     *
     * @throws CreationPendingException если исход прошлого запроса ещё неизвестен
     */
    private function claimCreation(string $key): void
    {
        if (Cache::add($key, now()->getTimestamp(), self::CREATE_PENDING_SECONDS)) {
            return;
        }

        $left = (int) Cache::get($key) + self::CREATE_PENDING_SECONDS - now()->getTimestamp();

        throw new CreationPendingException(max(1, $left) + 5);
    }

    /** Успех или отказ 4xx — исход известен. При 5xx и таймауте документ мог создаться. */
    private function settleCreation(string $key, Response $response): void
    {
        if ($response->successful() || $response->clientError()) {
            Cache::forget($key);
        }
    }
}
