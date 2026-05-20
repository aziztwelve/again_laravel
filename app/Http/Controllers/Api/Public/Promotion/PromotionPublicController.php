<?php

namespace App\Http\Controllers\Api\Public\Promotion;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Promotion\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PromotionPublicController extends Controller
{
    public function __construct(
        protected PromotionService $promotionService
    ) {}

    /**
     * Проверить применимые акции для корзины
     */
    public function checkApplicable(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'total' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка валидации',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $promotions = $this->promotionService->findApplicablePromotions(
            $validated['items'],
            $validated['total']
        );

        // Догружаем связи, нужные для выбора варианта подарка (размер/цвет и т.п.).
        // ВНИМАНИЕ: PromotionService::findApplicablePromotions возвращает обычную
        // Illuminate\Support\Collection (см. `collect()` внутри сервиса), у неё нет
        // loadMissing(); поэтому подгружаем через each() прямо на моделях.
        $promotions->each(function ($promotion) {
            $promotion->loadMissing([
                'giftProducts.activeVariants.optionValues.option',
                'giftProducts.activeVariants.images',
                'giftProducts.activeVariants.table_color',
            ]);
        });

        return response()->json([
            'success' => true,
            'data' => $promotions->map(function ($promotion) {
                return [
                    'id' => $promotion->id,
                    'name' => $promotion->name,
                    'description' => $promotion->description,
                    'allow_promo_codes' => $promotion->allow_promo_codes,
                    'min_purchase_amount' => $promotion->min_purchase_amount,
                    'priority' => $promotion->priority,
                    'gift_products' => $promotion->giftProducts->map(function ($product) {
                        return $this->mapGiftProduct($product);
                    }),
                ];
            }),
        ]);
    }

    /**
     * Получить список активных акций
     */
    public function index(Request $request): JsonResponse
    {
        $promotions = \App\Models\Promotion::where('is_active', true)
            ->where(function ($query) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '<=', now());
            })
            ->where(function ($query) {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', now());
            })
            ->where(function ($query) {
                $query->whereNull('max_uses')
                    ->orWhereRaw('times_used < max_uses');
            })
            ->with([
                'triggerProducts',
                'giftProducts.images',
                'giftProducts.activeVariants.optionValues.option',
                'giftProducts.activeVariants.images',
                'giftProducts.activeVariants.table_color',
            ])
            ->orderBy('priority', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $promotions->map(function ($promotion) {
                return [
                    'id' => $promotion->id,
                    'name' => $promotion->name,
                    'description' => $promotion->description,
                    'min_purchase_amount' => $promotion->min_purchase_amount,
                    'allow_promo_codes' => $promotion->allow_promo_codes,
                    'starts_at' => $promotion->starts_at,
                    'ends_at' => $promotion->ends_at,
                    'trigger_products' => $promotion->triggerProducts->map(function ($product) {
                        return [
                            'id' => $product->id,
                            'name' => $product->name,
                        ];
                    }),
                    'gift_products' => $promotion->giftProducts->map(function ($product) {
                        return $this->mapGiftProduct($product);
                    }),
                ];
            }),
        ]);
    }

    /**
     * Сформировать единое представление подарка с вариантами (если есть).
     *
     * Возвращаем только варианты в наличии (stock_quantity > 0).
     * Если у товара включён флаг has_variants, но активных вариантов в наличии нет —
     * variants будет пустым массивом; клиент должен это учитывать.
     */
    protected function mapGiftProduct(Product $product): array
    {
        $hasVariants = (bool) $product->has_variants;

        $variants = [];
        if ($hasVariants && $product->relationLoaded('activeVariants')) {
            $variants = $product->activeVariants
                ->filter(function (ProductVariant $variant) {
                    $stock = $variant->stock_quantity;
                    // NULL или пустая строка — считаем доступным (не управляем остатком)
                    if ($stock === null || $stock === '') return true;
                    return (float) $stock > 0;
                })
                ->values()
                ->map(function (ProductVariant $variant) {
                    return [
                        'id' => $variant->id,
                        'name' => $variant->name ?? null,
                        'sku' => $variant->sku ?? null,
                        'stock_quantity' => (float) $variant->stock_quantity,
                        'image' => $variant->images->first()?->url ?? null,
                        'color' => $variant->table_color ? [
                            'id' => $variant->table_color->id,
                            'name' => $variant->table_color->name,
                            'code' => $variant->table_color->code,
                        ] : null,
                        'option_values' => $variant->optionValues->map(function ($optionValue) {
                            return [
                                'id' => $optionValue->id,
                                'name' => $optionValue->name,
                                'value' => $optionValue->value ?? null,
                                'color_code' => $optionValue->color_code,
                                'option' => [
                                    'id' => $optionValue->option?->id,
                                    'name' => $optionValue->option?->name,
                                ],
                            ];
                        })->values(),
                    ];
                })
                ->all();
        }

        return [
            'id' => $product->id,
            'name' => $product->name,
            'price' => $product->price,
            'quantity' => $product->pivot->quantity ?? 1,
            'image' => $product->images->first()?->url ?? null,
            'has_variants' => $hasVariants,
            'variants' => $variants,
        ];
    }
}
