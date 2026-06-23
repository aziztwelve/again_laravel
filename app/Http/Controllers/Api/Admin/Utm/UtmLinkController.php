<?php

namespace App\Http\Controllers\Api\Admin\Utm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Utm\StoreUtmLinkRequest;
use App\Http\Requests\Utm\UpdateUtmLinkRequest;
use App\Http\Resources\Utm\UtmLinkResource;
use App\Models\UtmLink;
use App\Services\Utm\UtmLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UtmLinkController extends Controller
{
    public function __construct(protected UtmLinkService $utmLinkService) {}

    public function index(): AnonymousResourceCollection
    {
        $links = UtmLink::with(['channel', 'tag'])
            ->orderByDesc('id')
            ->get();

        return UtmLinkResource::collection($links);
    }

    public function store(StoreUtmLinkRequest $request): JsonResponse
    {
        $link = $this->utmLinkService->create($request->validated());

        return response()->json([
            'message' => 'UTM-метка успешно создана',
            'data' => new UtmLinkResource($link->load(['channel', 'tag'])),
        ], 201);
    }

    public function show(UtmLink $utmLink): UtmLinkResource
    {
        return new UtmLinkResource($utmLink->load(['channel', 'tag']));
    }

    public function update(UpdateUtmLinkRequest $request, UtmLink $utmLink): JsonResponse
    {
        $link = $this->utmLinkService->update($utmLink, $request->validated());

        return response()->json([
            'message' => 'UTM-метка успешно обновлена',
            'data' => new UtmLinkResource($link->load(['channel', 'tag'])),
        ]);
    }

    public function destroy(UtmLink $utmLink): JsonResponse
    {
        // Soft delete: исторические заказы/посещения сохраняют ссылку на метку.
        $utmLink->delete();

        return response()->json([
            'message' => 'UTM-метка успешно удалена',
        ]);
    }
}
