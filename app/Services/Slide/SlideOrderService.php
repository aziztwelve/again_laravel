<?php

namespace App\Services\Slide;

use App\Models\Slide;
use Illuminate\Support\Facades\DB;

/**
 * Порядок слайдов главной: переставить слайд на нужное место так же, как это
 * сделано для «Товаров онлайн» (ProductOrderService): остальные активные
 * слайды между старым и новым местом сдвигаются на одну позицию, поэтому
 * одинаковых порядков у показываемых слайдов не появляется.
 */
class SlideOrderService
{
    /** @return array{success: bool, message: string, old_order: int, new_order: int} */
    public function move(Slide $slide, int $newOrder): array
    {
        return DB::transaction(function () use ($slide, $newOrder) {
            $oldOrder = $slide->order;

            if ($oldOrder === $newOrder) {
                return $this->result('Порядок не изменился', $oldOrder, $newOrder);
            }

            // Неактивный слайд на сайте не показывается — остальных не двигаем.
            if ($slide->is_active) {
                $others = Slide::query()->where('is_active', true)->where('id', '!=', $slide->id);

                if ($newOrder < $oldOrder) {
                    $others->whereBetween('order', [$newOrder, $oldOrder - 1])->increment('order');
                } else {
                    $others->whereBetween('order', [$oldOrder + 1, $newOrder])->decrement('order');
                }
            }

            $slide->update(['order' => $newOrder]);

            return $this->result("Порядок слайда изменён с {$oldOrder} на {$newOrder}", $oldOrder, $newOrder);
        });
    }

    private function result(string $message, int $old, int $new): array
    {
        return ['success' => true, 'message' => $message, 'old_order' => $old, 'new_order' => $new];
    }
}
