<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Delivery\FreeShippingRuleRequest;
use App\Models\Country;
use App\Models\FreeShippingRule;
use App\Models\Region;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Админка: правила бесплатной доставки (docs/tasks/free-shipping.md).
 *
 * Раздел дашборда: Настройки → Бесплатная доставка.
 */
class FreeShippingRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FreeShippingRule::query()
            ->with(['products:id,name', 'countries:id,name', 'regions:id,name'])
            ->orderByDesc('priority')
            ->orderBy('min_order_amount')
            ->orderBy('id');

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->has('is_active') && $request->get('is_active') !== '') {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $perPage = min(100, max(1, (int) $request->get('per_page', 25)));
        $rules = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $rules->getCollection()->map(fn (FreeShippingRule $rule) => $this->present($rule))->all(),
            'meta' => [
                'current_page' => $rules->currentPage(),
                'last_page' => $rules->lastPage(),
                'per_page' => $rules->perPage(),
                'total' => $rules->total(),
            ],
        ]);
    }

    /**
     * Справочники для селектов формы.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'services' => $this->dictionary(config('free_shipping.services', [])),
            'delivery_types' => $this->dictionary(config('free_shipping.delivery_types', [])),
            'payment_methods' => $this->dictionary(config('free_shipping.payment_methods', [])),
            'countries' => Country::query()
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name, 'code' => $c->code])
                ->all(),
            'regions' => Region::query()
                ->orderBy('name')
                ->get(['id', 'name', 'country_id'])
                ->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'name' => $r->name,
                    'country_id' => (int) $r->country_id,
                ])
                ->all(),
        ]);
    }

    /**
     * Лёгкий поиск товаров для мультивыбора в форме правила.
     *
     * Отдельно от общего /api/products: тот тянет остатки МоегоСклада и
     * скидки — для селекта это лишний вес.
     */
    public function products(Request $request): JsonResponse
    {
        $search = trim((string) $request->get('search', ''));

        $query = \App\Models\Product::query()
            ->select(['id', 'name', 'price'])
            ->orderBy('name');

        if ($search !== '') {
            $query->where('name', 'like', "%{$search}%");
        }

        // Явно выбранные товары должны приходить даже если не попали в поиск,
        // иначе форма не сможет показать их названия.
        $selected = array_filter(array_map('intval', (array) $request->get('ids', [])));

        $products = $query->limit(30)->get();

        if ($selected !== []) {
            $missing = \App\Models\Product::query()
                ->select(['id', 'name', 'price'])
                ->whereIn('id', $selected)
                ->whereNotIn('id', $products->pluck('id'))
                ->get();

            $products = $products->concat($missing);
        }

        return response()->json([
            'success' => true,
            'data' => $products->map(fn ($p) => [
                'id' => (int) $p->id,
                'name' => $p->name,
                'price' => (float) $p->price,
            ])->values()->all(),
        ]);
    }

    public function show(FreeShippingRule $rule): JsonResponse
    {
        $rule->load(['products:id,name', 'countries:id,name', 'regions:id,name']);

        return response()->json([
            'success' => true,
            'data' => $this->present($rule),
        ]);
    }

    public function store(FreeShippingRuleRequest $request): JsonResponse
    {
        $rule = DB::transaction(function () use ($request) {
            $rule = FreeShippingRule::create($this->attributes($request));
            $this->syncRelations($rule, $request);

            return $rule;
        });

        $rule->load(['products:id,name', 'countries:id,name', 'regions:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Правило бесплатной доставки создано',
            'data' => $this->present($rule),
        ], 201);
    }

    public function update(FreeShippingRuleRequest $request, FreeShippingRule $rule): JsonResponse
    {
        DB::transaction(function () use ($request, $rule) {
            $rule->update($this->attributes($request));
            $this->syncRelations($rule, $request);
        });

        $rule->load(['products:id,name', 'countries:id,name', 'regions:id,name']);

        return response()->json([
            'success' => true,
            'message' => 'Правило обновлено',
            'data' => $this->present($rule->refresh()),
        ]);
    }

    public function destroy(FreeShippingRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json([
            'success' => true,
            'message' => 'Правило удалено',
        ]);
    }

    /**
     * Быстрое включение/выключение из списка.
     */
    public function toggle(FreeShippingRule $rule): JsonResponse
    {
        $rule->update(['is_active' => ! $rule->is_active]);

        return response()->json([
            'success' => true,
            'data' => ['id' => $rule->id, 'is_active' => (bool) $rule->is_active],
        ]);
    }

    private function attributes(FreeShippingRuleRequest $request): array
    {
        $attributes = [];

        foreach (['name', 'min_order_amount', 'priority', 'starts_at', 'ends_at'] as $field) {
            if ($request->has($field)) {
                $attributes[$field] = $request->input($field);
            }
        }

        if ($request->has('is_active')) {
            $attributes['is_active'] = $request->boolean('is_active');
        }

        // Пустой массив храним как NULL — «условие не ограничивает».
        foreach (['services', 'delivery_types', 'payment_methods'] as $field) {
            if ($request->has($field)) {
                $values = array_values(array_unique(array_filter(
                    (array) $request->input($field, []),
                    fn ($v) => $v !== null && $v !== ''
                )));
                $attributes[$field] = $values === [] ? null : $values;
            }
        }

        if (($attributes['priority'] ?? null) === null && ! $request->has('priority')) {
            unset($attributes['priority']);
        }

        return $attributes;
    }

    private function syncRelations(FreeShippingRule $rule, FreeShippingRuleRequest $request): void
    {
        if ($request->has('product_ids')) {
            $rule->products()->sync($this->ids($request->input('product_ids', [])));
        }

        if ($request->has('country_ids')) {
            $rule->countries()->sync($this->ids($request->input('country_ids', [])));
        }

        if ($request->has('region_ids')) {
            $rule->regions()->sync($this->ids($request->input('region_ids', [])));
        }
    }

    /**
     * ВНИМАНИЕ: id = 0 валиден (Россия), поэтому фильтруем только NULL/''.
     */
    private function ids($values): array
    {
        return array_values(array_unique(array_map(
            fn ($v) => (int) $v,
            array_filter((array) $values, fn ($v) => $v !== null && $v !== '')
        )));
    }

    private function present(FreeShippingRule $rule): array
    {
        $services = config('free_shipping.services', []);
        $types = config('free_shipping.delivery_types', []);
        $payments = config('free_shipping.payment_methods', []);

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'is_active' => (bool) $rule->is_active,
            'priority' => (int) $rule->priority,
            'min_order_amount' => (float) $rule->min_order_amount,
            'services' => $rule->services ?? [],
            'services_labels' => array_map(fn ($c) => $services[$c] ?? $c, $rule->services ?? []),
            'delivery_types' => $rule->delivery_types ?? [],
            'delivery_types_labels' => array_map(fn ($c) => $types[$c] ?? $c, $rule->delivery_types ?? []),
            'payment_methods' => $rule->payment_methods ?? [],
            'payment_methods_labels' => array_map(fn ($c) => $payments[$c] ?? $c, $rule->payment_methods ?? []),
            'product_ids' => $rule->relationLoaded('products')
                ? $rule->products->pluck('id')->map(fn ($id) => (int) $id)->all()
                : [],
            'products' => $rule->relationLoaded('products')
                ? $rule->products->map(fn ($p) => ['id' => (int) $p->id, 'name' => $p->name])->all()
                : [],
            'country_ids' => $rule->relationLoaded('countries')
                ? $rule->countries->pluck('id')->map(fn ($id) => (int) $id)->all()
                : [],
            'countries' => $rule->relationLoaded('countries')
                ? $rule->countries->map(fn ($c) => ['id' => (int) $c->id, 'name' => $c->name])->all()
                : [],
            'region_ids' => $rule->relationLoaded('regions')
                ? $rule->regions->pluck('id')->map(fn ($id) => (int) $id)->all()
                : [],
            'regions' => $rule->relationLoaded('regions')
                ? $rule->regions->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name])->all()
                : [],
            'starts_at' => $rule->starts_at?->toDateTimeString(),
            'ends_at' => $rule->ends_at?->toDateTimeString(),
            'created_at' => $rule->created_at?->toDateTimeString(),
        ];
    }

    private function dictionary(array $map): array
    {
        $result = [];

        foreach ($map as $code => $label) {
            $result[] = ['code' => $code, 'label' => $label];
        }

        return $result;
    }
}
