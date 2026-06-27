<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\CartItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Аналитика по брошенным корзинам (раздел Продвижение → Брошенные корзины).
 * Метрики и график соответствуют эталону InSales — см. docs/tasks/abandoned-cart.md.
 */
class CartAnalyticsController extends Controller
{
    public function cartAnalytics(Request $request)
    {
        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'granularity' => 'nullable|in:day,month',
        ]);

        [$from, $to] = $this->resolvePeriod($request);

        $allCarts = Cart::query()
            ->whereBetween('created_at', [$from, $to])
            ->get();

        $abandonedCarts = $allCarts->where('status', 'abandoned');
        $orderedCarts = $allCarts->where('status', 'ordered');

        $totalCarts = $allCarts->count();
        $totalAbandoned = $abandonedCarts->count();
        $totalOrdered = $orderedCarts->count();

        $lostRevenue = (float) $abandonedCarts->sum('total');
        $lostDiscount = (float) $abandonedCarts->sum('total_discount');

        $totalRevenue = (float) $orderedCarts->sum('total');
        $totalDiscount = (float) $orderedCarts->sum('total_discount');

        // «Средняя стоимость корзины» (решение #6) — среднее по брошенным.
        $averageCartValue = $totalAbandoned ? round($lostRevenue / $totalAbandoned, 2) : 0;
        // Оставляем прежний показатель (среднее по заказанным) для обратной совместимости.
        $avgOrderValue = $totalOrdered ? round($totalRevenue / $totalOrdered, 2) : 0;
        $avgDiscount = $totalOrdered ? round($totalDiscount / $totalOrdered, 2) : 0;

        // Конверсия в заказ: заказы / (заказы + брошенные).
        $conversionBase = $totalOrdered + $totalAbandoned;
        $conversionRate = $conversionBase ? round($totalOrdered / $conversionBase * 100, 1) : 0;

        // Разрез guest / registered (универсальная корзина, см.
        // docs/tasks/universal-cart.md): гость = client_id IS NULL.
        $segment = function (callable $predicate) use ($abandonedCarts, $orderedCarts) {
            $ab = $abandonedCarts->filter($predicate)->count();
            $or = $orderedCarts->filter($predicate)->count();
            $base = $ab + $or;

            return [
                'abandoned' => $ab,
                'ordered' => $or,
                'total' => $base,
                'rate' => $base ? round($or / $base * 100, 1) : 0,
                'lost_revenue' => (float) $abandonedCarts->filter($predicate)->sum('total'),
                'revenue' => (float) $orderedCarts->filter($predicate)->sum('total'),
            ];
        };

        $segments = [
            'guest' => $segment(fn ($c) => is_null($c->client_id)),
            'registered' => $segment(fn ($c) => ! is_null($c->client_id)),
        ];

        $totalItems = CartItem::query()
            ->join('cart', 'cart_items.cart_id', '=', 'cart.id')
            ->whereBetween('cart.created_at', [$from, $to])
            ->sum('cart_items.quantity');

        $topProducts = CartItem::query()
            ->join('products', 'cart_items.product_id', '=', 'products.id')
            ->join('cart', 'cart_items.cart_id', '=', 'cart.id')
            ->whereBetween('cart.created_at', [$from, $to])
            ->select('products.name', DB::raw('SUM(cart_items.quantity) as total_quantity'))
            ->groupBy('cart_items.product_id', 'products.name')
            ->orderByDesc('total_quantity')
            ->limit(5)
            ->get();

        $chart = $this->buildChart($from, $to, $request->query('granularity'));

        return response()->json([
            'success' => true,
            'data' => [
                // --- карточки со скрина ---
                'average_cart_value' => $averageCartValue, // Средняя стоимость корзины (по брошенным)
                'lost_revenue' => $lostRevenue,            // Упущенный доход
                'abandoned_count' => $totalAbandoned,      // Незаказанные брошенные корзины

                // --- конверсия в заказ (круговая) ---
                'conversion' => [
                    'ordered' => $totalOrdered,
                    'abandoned' => $totalAbandoned,
                    'total' => $conversionBase,
                    'rate' => $conversionRate, // %
                ],

                // --- разрез гость / зарегистрированный ---
                'segments' => $segments,

                // --- динамика по дням/месяцам ---
                'chart' => $chart,

                // --- сводные показатели / обратная совместимость ---
                'total_carts' => $totalCarts,
                'abandoned_carts' => $totalAbandoned,
                'ordered_carts' => $totalOrdered,
                'total_revenue' => $totalRevenue,        // оборот заказанных корзин
                'total_discount' => $totalDiscount,
                'lost_discount' => $lostDiscount,        // упущенные скидки
                'average_order_value' => $avgOrderValue, // среднее по заказанным (legacy)
                'average_discount' => $avgDiscount,
                'total_items_qty' => $totalItems,
                'top_products' => $topProducts,

                'period' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
            ],
        ]);
    }

    /**
     * Период анализа. По умолчанию — последние 30 дней (как дефолт на скрине).
     * preset=all → min/max created_at по корзинам.
     *
     * @return array{0:Carbon,1:Carbon}
     */
    protected function resolvePeriod(Request $request): array
    {
        if ($request->query('preset') === 'all' || $request->boolean('all')) {
            $min = Cart::query()->min('created_at');
            $max = Cart::query()->max('created_at');
            $from = $min ? Carbon::parse($min)->startOfDay() : now()->subDays(29)->startOfDay();
            $to = $max ? Carbon::parse($max)->endOfDay() : now()->endOfDay();

            return [$from, $to];
        }

        $from = $request->filled('date_from')
            ? Carbon::parse($request->query('date_from'))->startOfDay()
            : now()->subDays(29)->startOfDay();
        $to = $request->filled('date_to')
            ? Carbon::parse($request->query('date_to'))->endOfDay()
            : now()->endOfDay();

        return [$from, $to];
    }

    /**
     * Динамика «Брошенные корзины» vs «Заказы» по бакетам периода + оборот
     * заказанных корзин по бакетам.
     */
    protected function buildChart(Carbon $from, Carbon $to, ?string $granularity): array
    {
        $rangeDays = $from->diffInDays($to) + 1;
        if (! in_array($granularity, ['day', 'month'], true)) {
            $granularity = $rangeDays <= 31 ? 'day' : 'month';
        }

        $bucketFormat = $granularity === 'day' ? '%Y-%m-%d' : '%Y-%m';

        $raw = Cart::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('status', ['abandoned', 'ordered'])
            ->select([
                DB::raw("DATE_FORMAT(created_at, \"$bucketFormat\") as bucket"),
                'status',
                DB::raw('COUNT(*) as cnt'),
                DB::raw('SUM(total) as amount'),
            ])
            ->groupBy('bucket', 'status')
            ->get();

        Carbon::setLocale('ru');
        $crossYears = $from->year !== $to->year;

        $buckets = [];
        $labels = [];
        if ($granularity === 'day') {
            for ($date = $from->copy()->startOfDay(); $date <= $to; $date->addDay()) {
                $buckets[] = $date->format('Y-m-d');
                $labels[] = $date->translatedFormat('d.m');
            }
        } else {
            for ($date = $from->copy()->startOfMonth(); $date <= $to; $date->addMonth()) {
                $buckets[] = $date->format('Y-m');
                $labels[] = $crossYears ? $date->translatedFormat('F Y') : $date->translatedFormat('F');
            }
        }

        $abandoned = [];
        $ordered = [];
        $orderedAmount = [];

        foreach ($buckets as $bucket) {
            $abRow = $raw->first(fn ($r) => $r->bucket === $bucket && $r->status === 'abandoned');
            $orRow = $raw->first(fn ($r) => $r->bucket === $bucket && $r->status === 'ordered');

            $abandoned[] = (int) ($abRow->cnt ?? 0);
            $ordered[] = (int) ($orRow->cnt ?? 0);
            $orderedAmount[] = (float) ($orRow->amount ?? 0);
        }

        return [
            'labels' => $labels,
            'abandoned' => $abandoned,
            'ordered' => $ordered,
            'ordered_amount' => $orderedAmount,
            'granularity' => $granularity,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
        ];
    }
}
