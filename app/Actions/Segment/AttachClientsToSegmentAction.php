<?php

namespace App\Actions\Segment;

use App\Models\Client;
use App\Models\Segments\Segment;
use App\Repositories\SegmentRepository;
use App\Services\Segment\SegmentPromoCodeSyncService;
use Illuminate\Support\Facades\DB;

class AttachClientsToSegmentAction
{
    public function __construct(
        protected SegmentRepository $repository,
        protected SegmentPromoCodeSyncService $promoCodeSyncService
    ) {}

    /**
     * Выполнить добавление клиентов в сегмент
     */
    public function execute(Segment $segment, array $clientIds): void
    {
        if (empty($clientIds)) {
            throw new \InvalidArgumentException('Не указаны ID клиентов');
        }

        // Фильтр по verified_at снят: почти все клиенты боевой базы
        // неверифицированы, ручное добавление в сегмент было недоступно.
        $existingClientIds = Client::query()
            ->whereIn('id', $clientIds)
            ->pluck('id')
            ->all();

        if (count($existingClientIds) !== count(array_unique($clientIds))) {
            throw new \InvalidArgumentException('Некоторые из выбранных клиентов не существуют');
        }

        DB::transaction(function () use ($segment, $existingClientIds) {
            // Прикрепляем клиентов к сегменту
            $this->repository->attachClients($segment, $existingClientIds);

            // Синхронизируем промокоды с новыми клиентами
            $this->promoCodeSyncService->syncPromoCodeesToClients($segment, $existingClientIds);
        });
    }
}
