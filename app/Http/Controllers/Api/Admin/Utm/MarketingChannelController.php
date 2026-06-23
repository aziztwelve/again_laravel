<?php

namespace App\Http\Controllers\Api\Admin\Utm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Utm\StoreMarketingChannelRequest;
use App\Http\Requests\Utm\UpdateMarketingChannelRequest;
use App\Http\Resources\Utm\MarketingChannelResource;
use App\Models\MarketingChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class MarketingChannelController extends Controller
{
    /**
     * Список каналов маркетинга.
     */
    public function index(): AnonymousResourceCollection
    {
        $channels = MarketingChannel::orderBy('sort')->orderBy('name')->get();

        return MarketingChannelResource::collection($channels);
    }

    /**
     * Создать канал.
     */
    public function store(StoreMarketingChannelRequest $request): JsonResponse
    {
        $channel = MarketingChannel::create([
            ...$request->validated(),
            'is_system' => false,
        ]);

        return response()->json([
            'message' => 'Канал успешно создан',
            'data' => new MarketingChannelResource($channel),
        ], 201);
    }

    public function show(MarketingChannel $marketingChannel): MarketingChannelResource
    {
        return new MarketingChannelResource($marketingChannel);
    }

    /**
     * Обновить канал.
     */
    public function update(UpdateMarketingChannelRequest $request, MarketingChannel $marketingChannel): JsonResponse
    {
        $marketingChannel->update($request->validated());

        return response()->json([
            'message' => 'Канал успешно обновлён',
            'data' => new MarketingChannelResource($marketingChannel->fresh()),
        ]);
    }

    /**
     * Удалить канал. Системные (дефолтные) каналы удалять нельзя
     * (см. docs/tasks/utm-tracking.md, решение #11). Также запрещаем
     * удаление, если на канал ссылаются метки.
     */
    public function destroy(MarketingChannel $marketingChannel): JsonResponse
    {
        if ($marketingChannel->is_system) {
            return response()->json([
                'message' => 'Системный канал нельзя удалить',
            ], 422);
        }

        if ($marketingChannel->links()->exists()) {
            return response()->json([
                'message' => 'Нельзя удалить канал, к которому привязаны UTM-метки',
            ], 422);
        }

        $marketingChannel->delete();

        return response()->json([
            'message' => 'Канал успешно удалён',
        ]);
    }
}
