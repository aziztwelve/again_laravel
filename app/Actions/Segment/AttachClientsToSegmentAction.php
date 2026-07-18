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

        $authorizedClientIds = Client::query()
            ->whereIn('id', $clientIds)
            ->whereNotNull('verified_at')
            ->pluck('id')
            ->all();

        if (count($authorizedClientIds) !== count(array_unique($clientIds))) {
            throw new \InvalidArgumentException('В сегмент можно добавлять только авторизованных клиентов');
        }

        DB::transaction(function () use ($segment, $authorizedClientIds) {
            // Прикрепляем клиентов к сегменту
            $this->repository->attachClients($segment, $authorizedClientIds);

            // Синхронизируем промокоды с новыми клиентами
            $this->promoCodeSyncService->syncPromoCodeesToClients($segment, $authorizedClientIds);
        });
    }
}
