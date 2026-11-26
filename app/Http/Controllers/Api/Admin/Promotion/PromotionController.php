<?php

namespace App\Http\Controllers\Api\Admin\Promotion;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Services\Promotion\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PromotionController extends Controller
{
    public function __construct(
        protected PromotionService $promotionService
    ) {}

    /**
     * Список всех акций
     */
    public function index(Request $request): JsonResponse
    {
        // Сегменты нужны списку админки (колонка «Сегмент»).
        $query = Promotion::with(['triggerProducts', 'giftProducts', 'segments:segments.id,segments.name,segments.is_active']);

        // Фильтры
        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        if ($request->has('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }

        if ($request->has('status')) {
            $now = now();
            switch ($request->status) {
                case 'active':
                    $query->where('is_active', true)
                        ->where(function ($q) use ($now) {
                            $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                        })
                        ->where(function ($q) use ($now) {
                            $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
                        });
                    break;
                case 'scheduled':
                    $query->where('is_active', true)->where('starts_at', '>', $now);
                    break;
                case 'expired':
                    $query->where('ends_at', '<', $now);
                    break;
            }
        }

        $promotions = $query->orderBy('priority', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate($request->per_page ?? 15);

        $this->attachSelectedVariants($promotions->getCollection());

        return response()->json([
            'success' => true,
            'data' => $promotions,
        ]);
    }

    /**
     * Создать новую акцию
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'min_purchase_amount' => 'required|numeric|min:0',
            'allow_promo_codes' => 'required|boolean',
            'is_stackable' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'trigger_product_ids' => 'required|array|min:1',
            'trigger_product_ids.*' => 'exists:products,id',
            'gift_products' => 'required|array|min:1',
            'gift_products.*.product_id' => 'required|exists:products,id',
            'gift_products.*.quantity' => 'required|integer|min:1',
        ] + $this->variantRules(), $this->validationMessages(), $this->validationAttributes());

        $this->checkVariantOwnership($validator, $request);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $promotion = Promotion::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'min_purchase_amount' => $validated['min_purchase_amount'],
            'allow_promo_codes' => $validated['allow_promo_codes'],
            'is_stackable' => $validated['is_stackable'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
            'priority' => $validated['priority'] ?? 0,
            'max_uses' => $validated['max_uses'] ?? null,
        ]);

        // Привязываем товары-триггеры и товары-подарки
        $promotion->triggerProducts()->sync($this->triggerSyncData($validated['trigger_product_ids'], $request));
        $promotion->giftProducts()->sync($this->giftSyncData($validated['gift_products']));

        $promotion->load(['triggerProducts', 'giftProducts']);
        $this->attachSelectedVariants([$promotion]);

        return response()->json([
            'success' => true,
            'message' => 'Акция успешно создана',
            'data' => $promotion,
        ], 201);
    }

    /**
     * Показать акцию
     */
    public function show(Promotion $promotion): JsonResponse
    {
        $promotion->load(['triggerProducts', 'giftProducts', 'usages.giftProduct', 'usages.client']);

        $stats = $this->promotionService->getPromotionStats($promotion);
        $this->attachSelectedVariants([$promotion]);

        return response()->json([
            'success' => true,
            'data' => [
                'promotion' => $promotion,
                'stats' => $stats,
            ],
        ]);
    }

    /**
     * Обновить акцию
     */
    public function update(Request $request, Promotion $promotion): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'description' => 'nullable|string',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
            'min_purchase_amount' => 'numeric|min:0',
            'allow_promo_codes' => 'boolean',
            'is_stackable' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'integer|min:0',
            'max_uses' => 'nullable|integer|min:1',
            'trigger_product_ids' => 'array',
            'trigger_product_ids.*' => 'exists:products,id',
            'gift_products' => 'array',
            'gift_products.*.product_id' => 'required|exists:products,id',
            'gift_products.*.quantity' => 'required|integer|min:1',
        ] + $this->variantRules(), $this->validationMessages(), $this->validationAttributes());

        $this->checkVariantOwnership($validator, $request);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $promotion->update($validated);

        // Обновляем товары-триггеры
        if (isset($validated['trigger_product_ids'])) {
            $promotion->triggerProducts()->sync($this->triggerSyncData($validated['trigger_product_ids'], $request));
        }

        // Обновляем товары-подарки
        if (isset($validated['gift_products'])) {
            $promotion->giftProducts()->sync($this->giftSyncData($validated['gift_products']));
        }

        $promotion = $promotion->fresh(['triggerProducts', 'giftProducts']);
        $this->attachSelectedVariants([$promotion]);

        return response()->json([
            'success' => true,
            'message' => 'Акция успешно обновлена',
            'data' => $promotion,
        ]);
    }

    /**
     * Удалить акцию
     */
    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return response()->json([
            'success' => true,
            'message' => 'Акция успешно удалена',
        ]);
    }

    /**
     * Создать копию акции вместе со связями (товары-триггеры и товары-подарки).
     * Копия создаётся неактивной и со сброшенным счётчиком использований.
     */
    public function duplicate(Promotion $promotion): JsonResponse
    {
        $copy = $promotion->replicate();
        $copy->name = $this->buildCopyName($promotion->name);
        $copy->is_active = false;
        $copy->times_used = 0;
        $copy->save();

        // Товары-триггеры (с выбранными вариантами)
        $triggerSync = [];
        foreach ($promotion->triggerProducts as $trigger) {
            $triggerSync[$trigger->id] = ['variant_ids' => $trigger->pivot->variant_ids];
        }
        if (!empty($triggerSync)) {
            $copy->triggerProducts()->sync($triggerSync);
        }

        // Товары-подарки (с сохранением quantity и вариантов из pivot)
        $giftSync = [];
        foreach ($promotion->giftProducts as $gift) {
            $giftSync[$gift->id] = [
                'quantity' => $gift->pivot->quantity,
                'variant_ids' => $gift->pivot->variant_ids,
            ];
        }
        if (!empty($giftSync)) {
            $copy->giftProducts()->sync($giftSync);
        }

        return response()->json([
            'success' => true,
            'message' => 'Копия акции создана',
            'data' => $copy->load(['triggerProducts', 'giftProducts']),
        ], 201);
    }

    /**
     * Формирует имя для копии: «Название (копия)», «Название (копия 2)» и т.д.
     */
    private function buildCopyName(?string $name): string
    {
        $base = trim((string) $name);
        if ($base === '') {
            $base = 'Акция';
        }

        $candidate = $base.' (копия)';
        $i = 2;
        while (Promotion::where('name', $candidate)->exists()) {
            $candidate = $base.' (копия '.$i.')';
            $i++;
        }

        return $candidate;
    }

    /**
     * Получить список товаров для выбора
     */
    public function getProducts(Request $request): JsonResponse
    {
        $query = Product::where('is_active', true);

        if ($request->has('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }

        // sku и has_variants нужны фронту, чтобы показать в списке подарков,
        // что у товара есть размеры (клиент будет выбирать сам на checkout).
        // active_variants_count — сколько активных вариантов у товара (для индикатора).
        $products = $query->select('id', 'name', 'sku', 'price', 'stock_quantity', 'has_variants')
            ->withCount(['activeVariants as active_variants_count'])
            ->orderBy('name')
            ->paginate($request->per_page ?? 50);

        return response()->json([
            'success' => true,
            'data' => $products,
        ]);
    }

    /**
     * Получить статистику по акции
     */
    public function stats(Promotion $promotion): JsonResponse
    {
        $stats = $this->promotionService->getPromotionStats($promotion);

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }

    /**
     * Активировать/деактивировать акцию
     */
    public function toggleActive(Promotion $promotion): JsonResponse
    {
        $promotion->update([
            'is_active' => ! $promotion->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => $promotion->is_active ? 'Акция активирована' : 'Акция деактивирована',
            'data' => $promotion,
        ]);
    }

    /** Необязательный выбор вариантов: trigger_variants[product_id] и gift_products.*.variant_ids. */
    private function variantRules(): array
    {
        return [
            'trigger_variants' => 'nullable|array',
            'trigger_variants.*' => 'nullable|array',
            'trigger_variants.*.*' => 'integer',
            'gift_products.*.variant_ids' => 'nullable|array',
            'gift_products.*.variant_ids.*' => 'integer',
        ];
    }

    /** Варианты должны принадлежать своему товару (удалённые тоже считаются, чтобы акцию можно было сохранить). */
    private function checkVariantOwnership(\Illuminate\Validation\Validator $validator, Request $request): void
    {
        $validator->after(function ($validator) use ($request) {
            $selections = [];
            foreach ((array) $request->input('trigger_variants', []) as $productId => $ids) {
                $selections["trigger_variants.$productId"] = [$productId, $ids];
            }
            foreach ((array) $request->input('gift_products', []) as $index => $gift) {
                $selections["gift_products.$index.variant_ids"] = [$gift['product_id'] ?? 0, $gift['variant_ids'] ?? []];
            }

            foreach ($selections as $key => [$productId, $ids]) {
                $ids = $this->normalizeVariantIds($ids);
                if ($ids && ProductVariant::withTrashed()->where('product_id', (int) $productId)->whereIn('id', $ids)->count() !== count($ids)) {
                    $validator->errors()->add($key, 'Выбранный вариант не относится к этому товару.');
                }
            }
        });
    }

    /** NULL — товар участвует целиком. */
    private function normalizeVariantIds(mixed $ids): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($ids) ? $ids : []))));

        return $ids ?: null;
    }

    /** Без поля trigger_variants в запросе выбор вариантов у товаров не трогаем. */
    private function triggerSyncData(array $productIds, Request $request): array
    {
        $variants = (array) $request->input('trigger_variants', []);
        $data = [];
        foreach ($productIds as $productId) {
            $data[(int) $productId] = $request->has('trigger_variants')
                ? ['variant_ids' => $this->normalizeVariantIds($variants[$productId] ?? [])]
                : [];
        }

        return $data;
    }

    private function giftSyncData(array $gifts): array
    {
        $data = [];
        foreach ($gifts as $gift) {
            $row = ['quantity' => $gift['quantity']];
            if (array_key_exists('variant_ids', $gift)) {
                $row['variant_ids'] = $this->normalizeVariantIds($gift['variant_ids']);
            }
            $data[(int) $gift['product_id']] = $row;
        }

        return $data;
    }

    /** Названия выбранных вариантов — чтобы админка показывала «100 мл», а не только ID. */
    private function attachSelectedVariants(iterable $promotions): void
    {
        $products = collect($promotions)->flatMap(
            fn (Promotion $promotion) => array_merge($promotion->triggerProducts->all(), $promotion->giftProducts->all())
        );

        $ids = $products->flatMap(fn (Product $product) => $product->pivot->variant_ids ?? [])->unique()->values();
        $variants = $ids->isEmpty()
            ? collect()
            : ProductVariant::with('table_color')->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($products as $product) {
            $product->setAttribute('selected_variants', collect($product->pivot->variant_ids ?? [])
                ->map(fn ($id) => $variants->get($id))
                ->filter()
                ->map(fn (ProductVariant $variant) => [
                    'id' => $variant->id,
                    'name' => $variant->name,
                    'color' => $variant->table_color?->name,
                ])
                ->values()
                ->all());
        }
    }

    private function validationMessages(): array
    {
        return [
            'name.required' => 'Укажите название акции.',
            'name.string' => 'Название акции должно быть строкой.',
            'name.max' => 'Название акции не должно превышать 255 символов.',
            'description.string' => 'Описание должно быть строкой.',
            'starts_at.date' => 'Дата начала должна быть корректной датой.',
            'ends_at.date' => 'Дата окончания должна быть корректной датой.',
            'ends_at.after' => 'Дата окончания должна быть позже даты начала.',
            'min_purchase_amount.required' => 'Укажите минимальную сумму покупки.',
            'min_purchase_amount.numeric' => 'Минимальная сумма покупки должна быть числом.',
            'min_purchase_amount.min' => 'Минимальная сумма покупки не может быть меньше 0.',
            'allow_promo_codes.required' => 'Укажите, разрешены ли промокоды.',
            'allow_promo_codes.boolean' => 'Поле разрешения промокодов должно быть да или нет.',
            'is_stackable.boolean' => 'Поле суммирования акции должно быть да или нет.',
            'is_active.boolean' => 'Поле активности должно быть да или нет.',
            'priority.integer' => 'Приоритет должен быть целым числом.',
            'priority.min' => 'Приоритет не может быть меньше 0.',
            'max_uses.integer' => 'Максимальное количество использований должно быть целым числом.',
            'max_uses.min' => 'Максимальное количество использований должно быть не меньше 1.',
            'trigger_product_ids.required' => 'Выберите хотя бы один товар-триггер.',
            'trigger_product_ids.array' => 'Товары-триггеры должны быть массивом.',
            'trigger_product_ids.min' => 'Выберите хотя бы один товар-триггер.',
            'trigger_product_ids.*.exists' => 'Выбран несуществующий товар-триггер.',
            'gift_products.required' => 'Выберите хотя бы один товар-подарок.',
            'gift_products.array' => 'Товары-подарки должны быть массивом.',
            'gift_products.min' => 'Выберите хотя бы один товар-подарок.',
            'gift_products.*.product_id.required' => 'Укажите товар-подарок.',
            'gift_products.*.product_id.exists' => 'Выбран несуществующий товар-подарок.',
            'gift_products.*.quantity.required' => 'Укажите количество товара-подарка.',
            'gift_products.*.quantity.integer' => 'Количество товара-подарка должно быть целым числом.',
            'gift_products.*.quantity.min' => 'Количество товара-подарка должно быть не меньше 1.',
        ];
    }

    private function validationAttributes(): array
    {
        return [
            'name' => 'название акции',
            'description' => 'описание',
            'starts_at' => 'дата начала',
            'ends_at' => 'дата окончания',
            'min_purchase_amount' => 'минимальная сумма покупки',
            'allow_promo_codes' => 'разрешение промокодов',
            'is_stackable' => 'суммирование акции',
            'is_active' => 'активность',
            'priority' => 'приоритет',
            'max_uses' => 'максимальное количество использований',
            'trigger_product_ids' => 'товары-триггеры',
            'gift_products' => 'товары-подарки',
        ];
    }
}
