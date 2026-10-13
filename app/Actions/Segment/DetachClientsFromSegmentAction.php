<?php

namespace App\Actions\Segment;

use App\Models\Segments\Segment;
use App\Repositories\SegmentRepository;
use App\Services\Segment\SegmentPromoCodeSyncService;
use Illuminate\Support\Facades\DB;

class DetachClientsFromSegmentAction
{
    public function __construct(
        protected SegmentRepository $repository,
        protected SegmentPromoCodeSyncService $promoCodeSyncService
    ) {}

    /**
     * Выполнить удаление клиентов из сегмента
     */
    public function execute(Segment $segment, array $clientIds): array
    {
        if (empty($clientIds)) {
            throw new \InvalidArgumentException('Не указаны ID клиентов');
        }

        return DB::transaction(function () use ($segment, $clientIds) {
            Segment::whereKey($segment->id)->lockForUpdate()->firstOrFail();
            $removed = $this->repository->detachClients($segment, $clientIds);
            $this->promoCodeSyncService->removePromoCodesFromClients($segment, $removed);
            return $removed;
        });
    }
}
