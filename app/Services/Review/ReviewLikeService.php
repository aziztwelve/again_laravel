<?php

namespace App\Services\Review;

use App\Models\Review\Review;
use App\Models\Review\ReviewLike;
use Illuminate\Support\Facades\DB;

class ReviewLikeService
{
    /**
     * Поставить лайк отзыву
     *
     * @param Review $review
     * @param int $clientId
     * @return array
     */
    public function likeReview(Review $review, int $clientId): array
    {
        try {
            DB::beginTransaction();

            // Проверяем, не лайкнул ли уже клиент этот отзыв
            ReviewLike::firstOrCreate([
                'review_id' => $review->id,
                'client_id' => $clientId,
            ]);

            DB::commit();

            return [
                'success' => true,
                'message' => 'Лайк успешно добавлен',
                'likes_count' => $review->likesCount(),
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return [
                'success' => false,
                'message' => 'Не удалось обновить отметку.',
            ];
        }
    }

    /**
     * Убрать лайк с отзыва
     *
     * @param Review $review
     * @param int $clientId
     * @return array
     */
    public function unlikeReview(Review $review, int $clientId): array
    {
        try {
            DB::beginTransaction();

            ReviewLike::where('review_id', $review->id)
                ->where('client_id', $clientId)
                ->delete();

            DB::commit();

            return [
                'success' => true,
                'message' => 'Лайк успешно убран',
                'likes_count' => $review->likesCount(),
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            report($e);
            return [
                'success' => false,
                'message' => 'Не удалось обновить отметку.',
            ];
        }
    }
}
