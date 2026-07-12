<?php

namespace App\Http\Controllers\Api\Public\Review;

use App\Http\Controllers\Controller;
use App\Http\Requests\Public\Review\IndexProductReviewsRequest;
use App\Http\Resources\Public\ProductReviewResource;
use App\Models\Client;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class ProductReviewController extends Controller
{
    public function __invoke(IndexProductReviewsRequest $request, Product $product): JsonResponse
    {
        abort_unless($product->is_active, 404);

        $actor = Auth::guard('sanctum')->user();
        $clientId = $actor instanceof Client ? $actor->id : null;
        $perPage = (int) $request->validated('per_page', 8);

        $query = $product->reviews()
            ->visibleOnStorefront()
            ->with('client.profile')
            ->withCount('likes');

        if ($clientId !== null) {
            $query->withExists([
                'likes as is_liked' => fn ($likes) => $likes->where('client_id', $clientId),
            ]);
        }

        $reviews = $query
            ->orderByRaw('CASE WHEN published_at IS NULL THEN 1 ELSE 0 END ASC')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        if ($clientId === null) {
            $reviews->getCollection()->each->setAttribute('is_liked', false);
        }

        return response()->json([
            'success' => true,
            'data' => ProductReviewResource::collection($reviews->items()),
            'meta' => [
                'current_page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
                'last_page' => $reviews->lastPage(),
                'has_more' => $reviews->currentPage() < $reviews->lastPage(),
            ],
        ])->withHeaders([
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
