<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ImageResource;
use App\Models\Cart;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Cart\CartResolver;
use App\Services\Cart\AbandonedCartService;
use App\Traits\ProductsTrait;
use DB;
use Illuminate\Http\Request;

class CartController extends Controller
{
    use ProductsTrait;

    public function __construct(
        private readonly CartResolver $resolver,
        private readonly AbandonedCartService $abandonedCartService,
    ) {
    }

    public function carts(Request $request)
    {
        $request->validate([
            'status' => 'nullable|in:abandoned,ordered',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'search' => 'nullable|string|max:255',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $search = trim((string) $request->query('search', ''));

        $carts = Cart::query()
            ->select('cart.*')
            // «N версий» — число корзин этого покупателя по идентичности
            // (client_id для клиента, guest_token для гостя). Подзапрос, без N+1.
            ->selectRaw(
                '(SELECT COUNT(*) FROM cart AS cv WHERE '
                .'(cart.client_id IS NOT NULL AND cv.client_id = cart.client_id) '
                .'OR (cart.client_id IS NULL AND cart.guest_token IS NOT NULL AND cv.guest_token = cart.guest_token)'
                .') AS versions_count'
            )
            ->with([
                'client.profile',
                'items',
                // Последняя коммуникация для колонок «Канал / Коммуникация / Тип».
                'communications' => fn ($q) => $q->orderByDesc('id'),
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    if (ctype_digit($search)) {
                        $sub->orWhere('cart.id', (int) $search);
                    }
                    $sub->orWhereHas('client', fn ($c) => $c->where('email', 'like', "%{$search}%"))
                        ->orWhereHas('client.profile', function ($p) use ($search) {
                            $p->where('phone', 'like', "%{$search}%")
                                ->orWhere('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%");
                        });
                });
            })
            ->orderByDesc('created_at')
            ->paginate($request->get('per_page', 10))
            ->through(function (Cart $cart) {
                $lastComm = $cart->communications->first();

                return [
                    'id' => $cart->id,
                    'status' => $cart->status, // active | abandoned | ordered
                    'total' => (float) $cart->total,
                    'positions_count' => $cart->items->count(),          // кол-во позиций
                    'items_qty' => (int) $cart->items->sum('quantity'),  // кол-во товаров (шт.)
                    'versions_count' => (int) ($cart->versions_count ?? 1), // «N версий»
                    'created_at' => $cart->created_at,
                    'updated_at' => $cart->updated_at,                   // «Последнее обновление»
                    'ordered_at' => $cart->ordered_at,
                    'abandoned_at' => $cart->abandoned_at,
                    'customer' => [
                        'name' => $cart->client?->profile?->full_name,
                        'phone' => $cart->client?->profile?->phone,
                        'email' => $cart->client?->email,
                    ],
                    'last_communication' => $lastComm ? [
                        'channel' => $lastComm->channel,   // «Канал»
                        'type' => $lastComm->type,         // «Тип коммуникации» (trigger)
                        'status' => $lastComm->status,
                        'sent_at' => $lastComm->sent_at,   // «Коммуникация» (дата)
                    ] : null,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $carts,
        ]);
    }

    /**
     * Ручная отправка напоминания по корзине из админки (шаг F).
     * См. docs/tasks/abandoned-cart.md.
     */
    public function remind(Request $request, Cart $cart)
    {
        $validated = $request->validate([
            'channel' => 'nullable|in:email,telegram,whatsapp,vk',
        ]);

        $result = $this->abandonedCartService->sendManual($cart, $validated['channel'] ?? null);

        if (! ($result['ok'] ?? false)) {
            $messages = [
                'not_eligible' => 'Корзина оформлена или пуста — отправка недоступна.',
                'no_consent' => 'Гость не давал согласия на рассылку.',
                'throttled' => 'Слишком частая отправка. Попробуйте позже.',
                'no_contact' => 'Нет доступного контакта для выбранного канала.',
            ];

            return response()->json([
                'success' => false,
                'message' => $messages[$result['reason'] ?? ''] ?? 'Не удалось отправить напоминание.',
            ], 422);
        }

        $comm = $result['communication'];

        return response()->json([
            'success' => true,
            'message' => 'Напоминание отправлено.',
            'communication' => [
                'channel' => $comm->channel,
                'type' => $comm->type,
                'status' => $comm->status,
                'sent_at' => $comm->sent_at,
            ],
        ]);
    }


    // function that addes multiple items to cart
    public function add_multiple_items_to_cart(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'items.*.color_id' => 'nullable|integer|exists:colors,id',
            'items.*.qty' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0'
        ]);

        // Корзина гостя или клиента — единая точка (CartResolver).
        $cart = $this->resolver->resolveOrCreate($request);

        $this->sync($cart, $validated['items'], true);

        return response()->json(['success' => true, 'message' => 'Items added to cart.']);
    }


    // function that addes single item to cart
    public function add_item_to_cart(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'product_variant_id' => 'nullable|integer|exists:product_variants,id',
            'color_id' => 'nullable|integer|exists:colors,id',
            'qty' => 'required|integer|min:1',
            'price' => 'required|numeric|min:0'
        ]);

        $cart = $this->resolver->resolveOrCreate($request);

        $this->sync($cart, [$validated], false);

        return response()->json(['success' => true, 'message' => 'Item added to cart.']);
    }


    public function remove_single_item_from_cart(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
            'product_variant_id' => 'nullable|integer|exists:product_variants,id',
        ]);

        $cart = $this->resolver->resolveActive($request);

        if (!$cart) {
            return response()->json([
                'success' => true,
                'message' => 'Активная корзина не найдена.',
            ]);
        }

        $itemQuery = $cart->items()
            ->where('product_id', $validated['product_id']);

        if (is_null($validated['product_variant_id'])) {
            $itemQuery->whereNull('product_variant_id');
        } else {
            $itemQuery->where('product_variant_id', $validated['product_variant_id']);
        }

        $item = $itemQuery->first();

        // if (!$item) {
        //     return response()->json([
        //         'success' => false,
        //         'message' => 'Товар не найден в корзине.',
        //     ]);
        // }

        if ($item) {
            $item->delete();
        }

        $cart->update([
            'total' => $cart->items()->sum('total'),
            'total_original' => $cart->items()->sum('total_original'),
            'total_discount' => $cart->items()->sum('total_discount'),
            // Фиксируем последнюю активность корзины — на это опирается детект
            // брошенных корзин (см. docs/tasks/abandoned-cart.md). Модель Cart
            // не управляет timestamps автоматически.
            'updated_at' => now(),
        ]);

        if ($cart->items()->count() === 0) {
            $cart->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Товар успешно удалён из корзины.',
        ]);
    }

    public function cancel_cart(Request $request)
    {
        $cart = $this->resolver->resolveActive($request);

        if ($cart) {
            $cart->update([
                'status' => 'abandoned',
                'updated_at' => now(),
            ]);
        }

        return response()->json(['success' => true, 'message' => 'Корзина отменена успешно!']);
    }

    /**
     * Захват контактов и согласия на рассылку (гостевой чекаут).
     * Делает корзину eligible для цепочки напоминаний при явном opt-in.
     * См. docs/tasks/universal-cart.md.
     */
    public function update_contact(Request $request)
    {
        $validated = $request->validate([
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:32',
            'consent' => 'nullable|boolean',
        ]);

        $cart = $this->resolver->resolveOrCreate($request);

        $attributes = ['last_activity_at' => now()];

        if (array_key_exists('email', $validated)) {
            $attributes['email'] = $validated['email'];
        }
        if (array_key_exists('phone', $validated)) {
            $attributes['phone'] = $validated['phone'];
        }
        if (array_key_exists('consent', $validated)) {
            $consent = (bool) $validated['consent'];
            $attributes['marketing_consent'] = $consent;
            $attributes['consent_at'] = $consent ? now() : null;
        }

        $cart->forceFill($attributes)->save();

        return response()->json(['success' => true, 'message' => 'Контактные данные сохранены.']);
    }

    // logic for finding products or variants
    // --
    // what does it do ?
    // for example let's imagine that we haven't entered to the application/website for a while,
    // for this period of non existing, products and variants were deleted or even no longer sold.
    // This api endpoint checks whether selected items in cart are still available or not.
    // Available products or variants will be returned in "items" field in response 
    // 
    // NOTE!
    // This route works both for authenticated and unauthenticated rotues!
    // That is why if you are authenticated do not forget to send your token in headers!!!!
    public function cart_items(Request $request)
    {
        // Корзина гостя или клиента — единая точка (CartResolver), без создания.
        $cart = $this->resolver->resolveActive($request);

        if (!$cart) {
            return response()->json([
                'success' => true,
                'items' => [],
            ]);
        }

        $found_cart_items = $cart->items()->get();

        $found_items = [];

        foreach ($found_cart_items as $item) {
            if (!is_null($item['product_variant_id'])) {
                $product_variant = ProductVariant
                    ::join('products', 'product_variants.product_id', 'products.id')
                    ->with([
                        'images' => function ($sql) {
                            $sql->orderBy("order", 'asc');
                        },
                    ])
                    ->where('product_variants.id', $item['product_variant_id'])
                    ->where('product_variants.product_id', $item['product_id'])
                    ->where('product_variants.is_active', true)
                    ->whereNull('product_variants.deleted_at')
                    ->whereNull('products.deleted_at')
                    ->select(['product_variants.*', 'products.description'])
                    ->first();

                if ($product_variant) {

                    // $has_color = true;
                    // if (!is_null($item['color_id'])) {
                    //     $find_color = DB::table('colorables')
                    //         ->where('colorable_type', ProductVariant::class)
                    //         ->where('colorable_id', $item['product_variant_id'])
                    //         ->where('color_id', $item['color_id'])
                    //         ->first();
                    //     if (!$find_color) {
                    //         $has_color = false;
                    //     }
                    // }

                    // if ($has_color) {
                    $found_items[] = $this->get_product_variants_fields($product_variant, $item);
                    // }
                }

            } else if (!is_null($item['product_id'])) {
                $product = Product
                    ::with([
                        'images' => function ($sql) {
                            $sql->orderBy("order", 'asc');
                        },
                    ])
                    ->where('id', $item['product_id'])
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->first();
                if ($product) {
                    // $has_color = true;
                    // if (!is_null($item['color_id'])) {
                    //     $find_color = DB::table('colorables')
                    //         ->where('colorable_type', Product::class)
                    //         ->where('colorable_id', $item['product_id'])
                    //         ->where('color_id', $item['color_id'])
                    //         ->first();
                    //     if (!$find_color) {
                    //         $has_color = false;
                    //     }
                    // }

                    // if ($has_color) {
                    $found_items[] = $this->get_product_fields($product, $item);
                    // }
                }
            }
        }

        // $this->sync($user, $found_items, true, true);

        return response()->json([
            'success' => true,
            'items' => $found_items,
        ]);
    }

    protected function get_product_fields($product, $item)
    {
        $this->applyDiscountToProduct($product);

        return [
            'product_id' => $product->id,
            'product_variant_id' => null,
            'color_id' => $item['color_id'] ?? null,
            'qty' => $item['qty'] ?? 1,
            "name" => $product->name,
            "slug" => $product->slug,
            "description" => $product->description,
            "price" => $product->price,
            "old_price" => $product->old_price,
            "discount_percentage" => $product->discount_percentage,
            "total_discount" => $product->total_discount,
            "currency" => $product->currency,
            'barcode' => $product->barcode,
            "images" => ImageResource::collection($product->images),
        ];
    }

    protected function get_product_variants_fields($product_variant, $item)
    {

        $this->applyDiscountToProduct($product_variant);

        return [
            'product_id' => $product_variant->product_id,
            'product_variant_id' => $product_variant->id,
            'color_id' => $item['color_id'] ?? null,
            'qty' => $item['qty'] ?? 1,
            "name" => $product_variant->name,
            "slug" => $product_variant->slug,
            "description" => $product_variant->description,
            "price" => $product_variant->price,
            "old_price" => $product_variant->old_price,
            "discount_percentage" => $product_variant->discount_percentage,
            "total_discount" => $product_variant->total_discount,
            "currency" => $product_variant->currency,
            'barcode' => $product_variant->barcode,
            "images" => ImageResource::collection($product_variant->images),
        ];
    }


    /**
     * Применить позиции к уже разрешённой корзине (гостя или клиента).
     * Корзину предоставляет CartResolver — здесь нет ветвления guest/auth.
     */
    private function sync(
        Cart $cart,
        array $found_items,
        bool $delete_others = true,
    ): void {
        if (empty($found_items)) {
            return;
        }

        if ($delete_others) {
            $keysToKeep = collect($found_items)->map(function ($item) {
                return [
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null,
                ];
            });

            $cart->items()->whereNot(function ($query) use ($keysToKeep) {
                foreach ($keysToKeep as $index => $key) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $query->{$method}(function ($subQuery) use ($key) {
                        $subQuery->where('product_id', $key['product_id'])
                            ->where(function ($q) use ($key) {
                                if (is_null($key['product_variant_id'])) {
                                    $q->whereNull('product_variant_id');
                                } else {
                                    $q->where('product_variant_id', $key['product_variant_id']);
                                }
                            });
                    });
                }
            })->delete();
        }

        foreach ($found_items as $item) {

            $originalPrice = null;
            $discountValue = 0;
            $product = null;

            if (!empty($item['product_variant_id'])) {
                $product = ProductVariant::find($item['product_variant_id']);
            } else {
                $product = Product::find($item['product_id']);
            }

            if ($product) {
                $originalPrice = $product->price ?? $item['price'];

                $discount = $product->discount();

                if ($discount && $discount->is_active) {
                    if ($discount->type === 'percentage') {
                        $discountValue = round(($originalPrice * $discount->value) / 100, 2);
                    } elseif ($discount->type === 'fixed') {
                        $discountValue = min($discount->value, $originalPrice);
                    }
                }
            }

            $finalPrice = $originalPrice - $discountValue;

            $cart->items()->updateOrCreate(
                [
                    'product_id' => $item['product_id'],
                    'product_variant_id' => $item['product_variant_id'] ?? null
                ],
                [
                    'quantity' => $item['qty'],
                    'color_id' => $item['color_id'] ?? null,
                    'price' => ($item['price'] ?? $finalPrice),
                    'price_original' => $originalPrice,
                    'total_discount' => $discountValue * $item['qty'],
                    'total' => ($item['price'] ?? $finalPrice) * $item['qty'],
                    'total_original' => $originalPrice * $item['qty'],
                ]
            );
        }

        $cart->update([
            'total' => $cart->items()->sum('total'),
            'total_original' => $cart->items()->sum('total_original'),
            'total_discount' => $cart->items()->sum('total_discount'),
            // Фиксируем последнюю активность корзины — на это опирается детект
            // брошенных корзин (см. docs/tasks/abandoned-cart.md). Модель Cart
            // не управляет timestamps автоматически.
            'updated_at' => now(),
        ]);
    }
}
