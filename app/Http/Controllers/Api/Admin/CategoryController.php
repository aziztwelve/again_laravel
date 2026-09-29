<?php

namespace App\Http\Controllers\Api\Admin;

use App\Helpers\PaginationHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProductNumberTwoResouce;
use App\Models\Category;
use App\Models\CategoryProduct;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Request $request)
    {

        $perPage = (int)$request->get('per_page', 15);

        // Получаем только корневые категории с их потомками
        $categories = Category::with('children');

        if ($request->get('id')) {
            $categories->where('id', $request->get('id'));
        }

        if ($request->get('search')) {
            $categories
                ->where('name', 'like', "%{$request->get('search')}%")
                ->orWhere('slug', 'like', "%{$request->get('search')}%");
        }


        if ($request->boolean('get_children', false)) {
            $categories->whereNotNull('parent_id');
        } else {
            $categories->whereIsRoot();
        }

        $categories = $categories
            ->defaultOrder()
            ->paginate($perPage);

        return response()->json([
            'data' => CategoryResource::collection($categories->items()),
            'meta' => PaginationHelper::format($categories),
        ]);

    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',

            'show_in_catalog_menu' => 'nullable|boolean',
            'show_as_home_banner' => 'nullable|boolean',
            'is_new_product' => 'nullable|boolean',
            'is_coming_soon' => 'nullable|boolean',
            'menu_order' => 'nullable|integer|min:0',
            'home_banner_order' => 'nullable|integer|min:0',
            'banner_image' => 'nullable|image|max:10240',


            'banner_image_desktop' => 'nullable|image|max:10240',
            'banner_image_mobile' => 'nullable|image|max:10240',

        ]);

        $category = new Category();
        $category->name = $validated['name'];
        $category->description = $validated['description'] ?? null;
        $category->show_in_catalog_menu = $validated['show_in_catalog_menu'] ?? false;
        $category->show_as_home_banner = $validated['show_as_home_banner'] ?? false;
        $category->is_new_product = $validated['is_new_product'] ?? false;
        $category->is_coming_soon = $validated['is_coming_soon'] ?? false;
        $category->menu_order = $validated['menu_order'] ?? 0;
        $category->home_banner_order = $validated['home_banner_order'] ?? 0;

        // Загрузка баннера
        if ($request->hasFile('banner_image')) {
            $path = $request->file('banner_image')->store('categories/banners', 'public');
            $category->banner_image = $path;
        }


        // Загрузка desktop баннера
        if ($request->hasFile('banner_image_desktop')) {
            $path = $request->file('banner_image_desktop')->store('categories/banners', 'public');
            $category->banner_image_desktop = $path;
        }

        // Загрузка mobile баннера
        if ($request->hasFile('banner_image_mobile')) {
            $path = $request->file('banner_image_mobile')->store('categories/banners', 'public');
            $category->banner_image_mobile = $path;
        }


        if (!empty($validated['parent_id'])) {
            $parent = Category::findOrFail($validated['parent_id']);
            $category->appendToNode($parent)->save();
        } else {
            $category->save();
        }

        if (!empty($validated['product_ids'])) {
            $category->products()->sync($this->productsWithPositions($validated['product_ids']));
        }

        return response()->json([
            'success' => true,
            'message' => 'Категория успешно создана.',
            'data' => CategoryResource::make($category),
        ], 201);
    }

    public function update(Category $category, Request $request)
    {

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'parent_id' => 'nullable|exists:categories,id',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',

            'show_in_catalog_menu' => 'nullable|boolean',
            'show_as_home_banner' => 'nullable|boolean',
            'is_new_product' => 'nullable|boolean',
            'is_coming_soon' => 'nullable|boolean',
            'menu_order' => 'nullable|integer|min:0',
            'home_banner_order' => 'nullable|integer|min:0',
            'banner_image' => 'nullable|image|max:5120',
            'remove_banner_image' => 'nullable|boolean',

            'banner_image_desktop' => 'nullable|image|max:5120',
            'banner_image_mobile' => 'nullable|image|max:5120',

            'remove_banner_image_desktop' => 'nullable|boolean',
            'remove_banner_image_mobile' => 'nullable|boolean',

        ]);

        $category->name = $validated['name'];
        $category->description = $validated['description'] ?? null;
        $category->show_in_catalog_menu = $validated['show_in_catalog_menu'] ?? $category->show_in_catalog_menu;
        $category->show_as_home_banner = $validated['show_as_home_banner'] ?? $category->show_as_home_banner;
        $category->is_new_product = $validated['is_new_product'] ?? $category->is_new_product;
        $category->is_coming_soon = $validated['is_coming_soon'] ?? $category->is_coming_soon;
        $category->menu_order = $validated['menu_order'] ?? $category->menu_order;
        $category->home_banner_order = $validated['home_banner_order'] ?? $category->home_banner_order;

        // Удаление старого баннера если загружен новый или если запрошено удаление
        if ($request->hasFile('banner_image') || $request->boolean('remove_banner_image')) {
            if ($category->banner_image && Storage::disk('public')->exists($category->banner_image)) {
                Storage::disk('public')->delete($category->banner_image);
            }
            $category->banner_image = null;
        }

        // Загрузка нового баннера
        if ($request->hasFile('banner_image')) {
            $path = $request->file('banner_image')->store('categories/banners', 'public');
            $category->banner_image = $path;
        }


        // Удаление старого desktop баннера если загружен новый или если запрошено удаление
        if ($request->hasFile('banner_image_desktop') || $request->boolean('remove_banner_image_desktop')) {
            if ($category->banner_image_desktop && Storage::disk('public')->exists($category->banner_image_desktop)) {
                Storage::disk('public')->delete($category->banner_image_desktop);
            }
            $category->banner_image_desktop = null;
        }

// Удаление старого mobile баннера если загружен новый или если запрошено удаление
        if ($request->hasFile('banner_image_mobile') || $request->boolean('remove_banner_image_mobile')) {
            if ($category->banner_image_mobile && Storage::disk('public')->exists($category->banner_image_mobile)) {
                Storage::disk('public')->delete($category->banner_image_mobile);
            }
            $category->banner_image_mobile = null;
        }


        // Загрузка нового desktop баннера
        if ($request->hasFile('banner_image_desktop')) {
            $path = $request->file('banner_image_desktop')->store('categories/banners', 'public');
            $category->banner_image_desktop = $path;
        }

// Загрузка нового mobile баннера
        if ($request->hasFile('banner_image_mobile')) {
            $path = $request->file('banner_image_mobile')->store('categories/banners', 'public');
            $category->banner_image_mobile = $path;
        }


        if (!empty($validated['parent_id']) && $validated['parent_id'] !== $category->parent_id) {
            if (!empty($validated['parent_id'])) {
                $parent = Category::findOrFail($validated['parent_id']);
                $category->appendToNode($parent)->save();
            } else {
                $category->makeRoot()->save();
            }
        } else {
            $category->save();
        }

        if (array_key_exists('product_ids', $validated)) {
            $category->products()->sync($this->productsWithPositions($validated['product_ids']));
        }

        return response()->json([
            'success' => true,
            'message' => 'Категория успешно обновлена',
            'data' => CategoryResource::make($category),
        ]);
    }

    public function destroy(Category $category)
    {
        $category->products()->detach();
        $category->delete();
        return response()->json(['message' => 'Категория удалена!']);
    }

    public function get_products_of_category(Request $request)
    {
        $validated = $request->validate([
            'category_id' => 'required|exists:categories,id',
        ]);

        $category = Category::where('id', $validated['category_id'])->first();

        $products = [];

        if ($category) {
            $category_children_ids = Category::whereNotNull('parent_id')
                ->where('parent_id', $category->id)
                ->pluck('id')
                ->toArray();

            $category_products_ids = CategoryProduct::where('category_id', $category->id)
                ->orWhereIn('category_id', $category_children_ids)
                ->orderBy('position')
                ->pluck('product_id')
                ->toArray();

            $products = Product::whereIn('id', $category_products_ids)
                ->orderByRaw('FIELD(id, ' . implode(',', $category_products_ids ?: [0]) . ')')
                ->get();
        }

        return response()->json([
            'category_id' => $category->id,
            'category_name' => $category->name,
            'products' => ProductNumberTwoResouce::collection($products),
        ]);
    }

    public function orderOptions(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['menu', 'home_banner'])],
        ]);

        $isMenu = $validated['type'] === 'menu';
        $orderColumn = $isMenu ? 'menu_order' : 'home_banner_order';
        $categories = Category::query()
            ->when($isMenu, fn ($query) => $query->where('show_in_catalog_menu', true))
            ->when(!$isMenu, fn ($query) => $query->where('show_as_home_banner', true))
            ->orderBy($orderColumn)
            ->orderBy('name')
            ->get(['id', 'name', 'parent_id', 'menu_order', 'home_banner_order']);

        $parentNames = Category::whereIn(
            'id',
            $categories->pluck('parent_id')->filter()->unique()
        )->pluck('name', 'id');

        $groups = $categories->groupBy(fn ($category) => $category->parent_id ?? 'root')
            ->map(function ($items, $parentId) use ($parentNames, $orderColumn, $isMenu) {
                $parentId = $parentId === 'root' ? null : (int) $parentId;

                return [
                    'parent_id' => $parentId,
                    'parent_name' => $parentId === null
                        ? ($isMenu ? 'Корневые категории' : 'Баннеры на главной')
                        : $parentNames[$parentId],
                    'categories' => $items->values()->map(fn ($category) => [
                        'id' => $category->id,
                        'name' => $category->name,
                        'parent_id' => $category->parent_id,
                        'order' => (int) $category->{$orderColumn},
                    ])->all(),
                ];
            })->values()->all();

        if ($isMenu) {
            usort($groups, function (array $left, array $right) {
                if ($left['parent_id'] === null) return -1;
                if ($right['parent_id'] === null) return 1;
                return strcasecmp($left['parent_name'], $right['parent_name']);
            });
        }

        return response()->json(['data' => $groups]);
    }

    public function reorder(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['menu', 'home_banner'])],
            'groups' => ['required', 'array', 'min:1'],
            'groups.*.parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'groups.*.category_ids' => ['required', 'array'],
            'groups.*.category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
        ]);

        $categoryIds = collect($validated['groups'])
            ->pluck('category_ids')
            ->flatten();
        abort_if($categoryIds->duplicates()->isNotEmpty(), 422, 'Категория указана несколько раз.');

        $isMenu = $validated['type'] === 'menu';
        $flagColumn = $isMenu ? 'show_in_catalog_menu' : 'show_as_home_banner';
        $orderColumn = $isMenu ? 'menu_order' : 'home_banner_order';

        DB::transaction(function () use ($validated, $flagColumn, $orderColumn) {
            foreach ($validated['groups'] as $group) {
                $query = Category::query()
                    ->whereIn('id', $group['category_ids'])
                    ->where($flagColumn, true);

                if ($group['parent_id'] === null) {
                    $query->whereNull('parent_id');
                } else {
                    $query->where('parent_id', $group['parent_id']);
                }

                abort_if($query->count() !== count($group['category_ids']), 422, 'Список категорий устарел. Обновите страницу.');

                foreach ($group['category_ids'] as $index => $categoryId) {
                    Category::whereKey($categoryId)->update([$orderColumn => $index + 1]);
                }
            }
        });

        return response()->json(['success' => true]);
    }

    /** @return array<int, array{position: int}> */
    private function productsWithPositions(array $productIds): array
    {
        return collect($productIds)
            ->mapWithKeys(fn ($productId, $index) => [(int) $productId => ['position' => $index + 1]])
            ->all();
    }


    /**
     * Получить URL изображения баннера категории
     * GET /api/categories/{category}/banner-image
     */
    public function getBannerImage(Category $category)
    {
        if (!$category->banner_image) {
            return response()->json([
                'success' => false,
                'message' => 'У категории нет баннера'
            ], 404);
        }

        $url = \Storage::disk('public')->url($category->banner_image);

        return response()->json([
            'success' => true,
            'url' => $url
        ]);
    }

}
