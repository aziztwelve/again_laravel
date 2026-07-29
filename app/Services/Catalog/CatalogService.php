<?php

namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class CatalogService
{
    /**
     * Получить категории для меню каталога
     */
    public function getCatalogMenuCategories()
    {
        return Category::query()
            ->where('show_in_catalog_menu', true)
            ->whereIsRoot()
            ->with(['children' => function ($q) {
                $q->where('show_in_catalog_menu', true)
                    ->orderBy('menu_order', 'asc')
                    ->orderBy('name', 'asc')
                    ->select(['id', 'name', 'slug', 'parent_id', 'is_new_product', 'is_coming_soon']);
            }])
            ->orderBy('menu_order', 'asc')
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'slug', 'is_new_product', 'is_coming_soon']);
    }


    /**
     * Получить категории для баннеров главной страницы
     */
    public function getHomeBanners()
    {
        $banners = Category::where('show_as_home_banner', true)
            ->orderBy('menu_order', 'asc')
            ->get([
                'id',
                'name',
                'slug',
                'description',
                'banner_image_desktop',
                'banner_image_mobile',
            ]);

        $banners->transform(function ($banner) {
            $banner->desktop_url = $banner->banner_image_desktop
                ? url('storage/' . $banner->banner_image_desktop)
                : null;

            $banner->mobile_url = $banner->banner_image_mobile
                ? url('storage/' . $banner->banner_image_mobile)
                : null;

            return $banner;
        });

        return $banners;
    }


    /**
     * Получить товары каталога с фильтрами
     *
     * @param array $filters - массив фильтров
     * @return Builder
     */
    public function getProductsQuery(array $filters = []): Builder
    {
        $query = Product::query()
            ->where('is_active', true)
            ->with([
                'images' => function ($q) {
                    $q->orderBy('order', 'asc');
                },
                'colors:id,name,code',
                'defaultUnit',
                'variants',
            ]);


        // Категория «Скоро в продаже»: показывать выбранные в админке товары
        // без остатка. Когда остаток появляется, товар уходит из этой категории.
        $isComingSoon = false;
        $category = null;

        // Фильтр по категории (ID или SLUG)
        if (!empty($filters['category_id']) || !empty($filters['category_slug'])) {

            // Получаем категорию
            $category = null;
            if (!empty($filters['category_id'])) {
                $category = Category::find($filters['category_id']);
            } elseif (!empty($filters['category_slug'])) {
                $category = Category::where('slug', $filters['category_slug'])->first();
            }

            if ($category) {
                // Если у категории включен флаг is_new_product
                if ($category->is_new_product) {
                    // Показываем ВСЕ товары с меткой "новинка"
                    $query->where('is_new', true);
                } elseif ($category->is_coming_soon) {
                    $isComingSoon = true;
                    $query
                        ->where('stock_quantity', '<=', 0)
                        ->whereHas('categories', function ($q) use ($category) {
                            $q->where('categories.id', $category->id);
                        });
                } else {
                    // Обычная логика - товары привязанные к категории
                    $query->whereHas('categories', function ($q) use ($category) {
                        $q->where('categories.id', $category->id);
                    });
                }
            }
        }

        // Фильтр по впитываемости
        if (!empty($filters['absorbency_level'])) {
            $query->where('absorbency_level', $filters['absorbency_level']);
        }

        // Фильтр по посадке
        if (!empty($filters['fit_type'])) {
            $query->where('fit_type', $filters['fit_type']);
        }

        // Фильтр "Новинки"
        if (!empty($filters['is_new'])) {
            $query->where('is_new', true);
        }

        // Фильтр по цвету (существующий)
        if (!empty($filters['color_id'])) {
            $query->whereHas('variants', function ($q) use ($filters) {
                $q->whereNull('deleted_at');
                $q->where('color_id', $filters['color_id']);
            });
        }

        // Фильтр по цене (существующий)
        if (!empty($filters['price_after'])) {
            $query->where('price', '>=', $filters['price_after']);
        }
        if (!empty($filters['price_before'])) {
            $query->where('price', '<=', $filters['price_before']);
        }

        // Обычный каталог не должен показывать товары без остатка: они уходят
        // в спецкатегорию «Скоро в продаже».
        if (!$isComingSoon) {
            $query->where('stock_quantity', '>', 0);
        }

        // Поиск (существующий)
        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('description', 'like', "%{$filters['search']}%");
            });
        }

        // Для ручной подборки «Новинки» порядок задаётся в админке категории.
        $isManualNewCategory = $category?->slug === 'novinki' && !$category->is_new_product;
        if ($isManualNewCategory) {
            $query->join('category_product as category_sort', function ($join) use ($category) {
                $join->on('category_sort.product_id', '=', 'products.id')
                    ->where('category_sort.category_id', $category->id);
            })->select('products.*');
        }

        // Сортировка: сперва товары в наличии, затем по выбранному полю.
        // Для категории «Скоро в продаже» все товары без остатка — сортируем
        // только по выбранному полю (по умолчанию display_order).
        $sortBy = $filters['sort_by'] ?? 'display_order';
        $sortOrder = $filters['sort_order'] ?? 'asc';

        if (!$isComingSoon) {
            $query->orderByRaw('CASE WHEN stock_quantity > 0 THEN 0 ELSE 1 END');
        }

        if ($isManualNewCategory) {
            $query->orderBy('category_sort.position');
        } else {
            $query->orderBy($sortBy, $sortOrder);
        }

        return $query;
    }


    public function getCategoryBySlug(string $slug): ?Category
    {
        return Category::whereSlug($slug)
            ->first();
    }


}
