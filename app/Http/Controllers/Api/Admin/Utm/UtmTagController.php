<?php

namespace App\Http\Controllers\Api\Admin\Utm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Utm\StoreUtmTagRequest;
use App\Http\Requests\Utm\UpdateUtmTagRequest;
use App\Http\Resources\Utm\UtmTagResource;
use App\Models\UtmTag;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UtmTagController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UtmTagResource::collection(UtmTag::orderBy('name')->get());
    }

    public function store(StoreUtmTagRequest $request): JsonResponse
    {
        $tag = UtmTag::create($request->validated());

        return response()->json([
            'message' => 'Тег успешно создан',
            'data' => new UtmTagResource($tag),
        ], 201);
    }

    public function show(UtmTag $utmTag): UtmTagResource
    {
        return new UtmTagResource($utmTag);
    }

    public function update(UpdateUtmTagRequest $request, UtmTag $utmTag): JsonResponse
    {
        $utmTag->update($request->validated());

        return response()->json([
            'message' => 'Тег успешно обновлён',
            'data' => new UtmTagResource($utmTag->fresh()),
        ]);
    }

    public function destroy(UtmTag $utmTag): JsonResponse
    {
        // Тег отвязывается от меток автоматически (utm_tag_id → NULL по FK).
        $utmTag->delete();

        return response()->json([
            'message' => 'Тег успешно удалён',
        ]);
    }
}
