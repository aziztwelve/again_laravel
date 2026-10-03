<?php

namespace App\Services\Segment;

use App\DTOs\Segment\SegmentConditionsDTO;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Order;
use App\Models\Segments\Segment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SegmentRecalculationService
{
    public function __construct(
        protected SegmentPromoCodeSyncService $promoCodeSyncService
    )
    {
    }

    /**
     * Пересчитать клиентов сегмента на основе условий
     */
    public function recalculate(Segment $segment): void
    {
        $conditions = SegmentConditionsDTO::fromArray($segment->conditions);

        if (!$conditions || !$conditions->hasConditions()) {
            return;
        }

        DB::transaction(function () use ($segment, $conditions) {
            // Получаем текущих клиентов сегмента
            $currentClientIds = $segment->clients()->pluck('clients.id')->toArray();

            // Находим клиентов, соответствующих условиям
            $newClientIds = $this->findClientsMatchingConditions($conditions);

            // Клиенты для добавления
            $clientsToAdd = array_diff($newClientIds, $currentClientIds);

            // Клиенты для удаления
            $clientsToRemove = array_diff($currentClientIds, $newClientIds);

            // Добавляем новых клиентов
            if (!empty($clientsToAdd)) {
                foreach (array_chunk($clientsToAdd, 500) as $chunk) {
                    $segment->clients()->attach($chunk, [
                        'added_at' => now()
                    ]);
                }

                // Синхронизируем промокоды с новыми клиентами
                $this->promoCodeSyncService->syncPromoCodeesToClients($segment, $clientsToAdd);
            }

            // Удаляем клиентов, не соответствующих условиям
            if (!empty($clientsToRemove)) {
                // Удаляем промокоды у клиентов
                $this->promoCodeSyncService->removePromoCodesFromClients($segment, $clientsToRemove);

                // Открепляем клиентов
                $segment->clients()->detach($clientsToRemove);
            }

            // Обновляем время последнего пересчёта
            $segment->markAsRecalculated();
        });
    }

    /**
     * Найти клиентов, соответствующих условиям
     */
    protected function findClientsMatchingConditions(SegmentConditionsDTO $conditions): array
    {
        // Верификация (clients.verified_at) не используется как фильтр: в боевой
        // базе верифицировано ~14 из 25 тыс. клиентов, из-за чего сегменты не
        // видели реальные заказы. Сегмент = все клиенты, подходящие под условия.
        $query = Client::query();

        // Подзапрос для расчёта статистики по заказам
        $query->select('clients.id')
            ->leftJoin('orders', function ($join) use ($conditions) {
                $join->on('clients.id', '=', 'orders.client_id')
                    ->where('orders.status', OrderStatus::DELIVERED)
                    ->where('orders.payment_status', PaymentStatus::PAID)
                    ->whereNull('orders.deleted_at');

                // Фильтр по периоду
                if ($startDate = $conditions->getStartDate()) {
                    $join->where('orders.created_at', '>=', $startDate);
                }

                if ($endDate = $conditions->getEndDate()) {
                    $join->where('orders.created_at', '<=', $endDate);
                }
            })
            ->groupBy('clients.id');

        // Условие: минимальное количество заказов
        if ($conditions->minOrdersCount !== null) {
            $query->havingRaw('COUNT(orders.id) >= ?', [$conditions->minOrdersCount]);
        }

        // Условие: максимальное количество заказов
        if ($conditions->maxOrdersCount !== null) {
            $query->havingRaw('COUNT(orders.id) <= ?', [$conditions->maxOrdersCount]);
        }

        // Условие: минимальная сумма заказов
        if ($conditions->minTotalAmount !== null) {
            $query->havingRaw('COALESCE(SUM(orders.total_amount), 0) >= ?', [$conditions->minTotalAmount]);
        }

        // Условие: минимальный средний чек
        if ($conditions->minAverageCheck !== null) {
            $query->havingRaw(
                'COALESCE(SUM(orders.total_amount) / NULLIF(COUNT(orders.id), 0), 0) >= ?',
                [$conditions->minAverageCheck]
            );
        }

        return $query->pluck('clients.id')->toArray();
    }

    /**
     * Пересчитать все активные сегменты.
     *
     * @param bool $onlyAutoRecalculable true — только сегменты с частотой on_view
     *                                   (штатный фоновый пересчёт); false — все
     *                                   активные, включая manual (ручная
     *                                   синхронизация из админки).
     * @return array<int, array{id: int, name: string, clients_count: int}>
     */
    public function recalculateAll(bool $onlyAutoRecalculable = true): array
    {
        $query = Segment::active();

        if ($onlyAutoRecalculable) {
            $query->where('recalculate_frequency', 'on_view');
        }

        $results = [];

        foreach ($query->get() as $segment) {
            $this->recalculate($segment);

            $results[] = [
                'id' => $segment->id,
                'name' => $segment->name,
                'clients_count' => $segment->clients()->count(),
            ];
        }

        return $results;
    }
}
