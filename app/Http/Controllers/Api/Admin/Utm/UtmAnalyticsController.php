<?php

namespace App\Http\Controllers\Api\Admin\Utm;

use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\UtmLink;
use App\Models\UtmVisit;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Аналитика по UTM-меткам: сводная таблица + данные для круговой диаграммы
 * и гистограммы. Метрики и формулы — см. docs/tasks/utm-tracking.md.
 */
class UtmAnalyticsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        try {
            [$from, $to] = $this->resolvePeriod($request);

            $channelId = $request->integer('channel_id') ?: null;
            $tagId = $request->integer('tag_id') ?: null;
            $linkId = $request->integer('link_id') ?: null;

            // Базовый набор меток с учётом фильтров.
            $links = UtmLink::with(['channel', 'tag'])
                ->when($channelId, fn ($q) => $q->where('marketing_channel_id', $channelId))
                ->when($tagId, fn ($q) => $q->where('utm_tag_id', $tagId))
                ->when($linkId, fn ($q) => $q->where('id', $linkId))
                ->orderByDesc('id')
                ->get();

            $linkIds = $links->pluck('id');

            $visitsByLink = $this->visitsByLink($linkIds, $from, $to);
            $ordersByLink = $this->ordersByLink($linkIds, $from, $to);

            $rows = [];
            $totals = [
                'visits' => 0,
                'orders' => 0,
                'orders_amount' => 0.0,
                'purchases' => 0,
                'purchases_amount' => 0.0,
                'clients' => 0,
            ];

            foreach ($links as $link) {
                $visits = (int) ($visitsByLink[$link->id]['unique_visits'] ?? 0);
                $agg = $ordersByLink[$link->id] ?? null;

                $orders = (int) ($agg->orders_count ?? 0);
                $ordersAmount = (float) ($agg->orders_amount ?? 0);
                $purchases = (int) ($agg->purchases_count ?? 0);
                $purchasesAmount = (float) ($agg->purchases_amount ?? 0);
                $clients = (int) ($agg->clients_count ?? 0);

                $rows[] = [
                    'link_id' => $link->id,
                    'name' => $link->name,
                    'channel' => $link->channel?->name,
                    'channel_id' => $link->marketing_channel_id,
                    'tag' => $link->tag?->name,
                    'tag_id' => $link->utm_tag_id,
                    'tracking_url' => $link->tracking_url,
                    'target_url_with_params' => $link->target_url_with_params,
                    'visits' => $visits,
                    'orders' => $orders,
                    'orders_amount' => round($ordersAmount, 2),
                    'purchases' => $purchases,
                    'purchases_amount' => round($purchasesAmount, 2),
                    'clients' => $clients,
                    // Конверсия в заказ = заказы / посещения * 100 (решение #6).
                    'cr_order' => $visits > 0 ? round($orders / $visits * 100, 1) : 0.0,
                    // Конверсия в покупку = покупки / заказы * 100 (решение #6).
                    'cr_purchase' => $orders > 0 ? round($purchases / $orders * 100, 1) : 0.0,
                ];

                $totals['visits'] += $visits;
                $totals['orders'] += $orders;
                $totals['orders_amount'] += $ordersAmount;
                $totals['purchases'] += $purchases;
                $totals['purchases_amount'] += $purchasesAmount;
                $totals['clients'] += $clients;
            }

            $totals['orders_amount'] = round($totals['orders_amount'], 2);
            $totals['purchases_amount'] = round($totals['purchases_amount'], 2);
            $totals['cr_order'] = $totals['visits'] > 0
                ? round($totals['orders'] / $totals['visits'] * 100, 1)
                : 0.0;
            $totals['cr_purchase'] = $totals['orders'] > 0
                ? round($totals['purchases'] / $totals['orders'] * 100, 1)
                : 0.0;

            return response()->json([
                'rows' => $rows,
                'totals' => $totals,
                'pie' => $this->buildPie($rows),
                'chart' => $this->buildChart($linkIds, $links, $from, $to, $request),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ]);
        } catch (QueryException $e) {
            Log::error('Database error in UTM analytics: '.$e->getMessage());

            return response()->json([
                'error' => 'Database Error',
                'message' => 'Ошибка при запросе к базе данных',
            ], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in UTM analytics: '.$e->getMessage());

            return response()->json([
                'error' => 'Internal Server Error',
                'message' => 'Не удалось получить аналитику',
            ], 500);
        }
    }

    /**
     * Период из запроса (from/to или preset=all). По умолчанию — последние 30 дней.
     */
    private function resolvePeriod(Request $request): array
    {
        if ($request->query('preset') === 'all' || $request->boolean('all')) {
            $from = Carbon::parse('2000-01-01')->startOfDay();
            $to = now()->endOfDay();

            return [$from, $to];
        }

        $from = $request->query('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : now()->subDays(30)->startOfDay();
        $to = $request->query('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : now()->endOfDay();

        return [$from, $to];
    }

    /**
     * Уникальные посещения по каждой метке за период (по visitor_hash).
     */
    private function visitsByLink($linkIds, Carbon $from, Carbon $to): array
    {
        if ($linkIds->isEmpty()) {
            return [];
        }

        return UtmVisit::query()
            ->whereIn('utm_link_id', $linkIds)
            ->whereBetween('visited_at', [$from, $to])
            ->select('utm_link_id', DB::raw('COUNT(DISTINCT visitor_hash) as unique_visits'))
            ->groupBy('utm_link_id')
            ->get()
            ->keyBy('utm_link_id')
            ->map(fn ($row) => ['unique_visits' => $row->unique_visits])
            ->toArray();
    }

    /**
     * Агрегаты заказов по каждой метке за период.
     * Покупки/сумма покупок — только paid (без refunded), решения #5/#9.
     */
    private function ordersByLink($linkIds, Carbon $from, Carbon $to)
    {
        if ($linkIds->isEmpty()) {
            return collect();
        }

        $paid = PaymentStatus::PAID->value;

        return DB::table('orders')
            ->whereNull('deleted_at')
            ->whereIn('utm_link_id', $linkIds)
            ->whereBetween('created_at', [$from, $to])
            ->select(
                'utm_link_id',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('COALESCE(SUM(total_amount), 0) as orders_amount'),
                DB::raw("SUM(CASE WHEN payment_status = '{$paid}' THEN 1 ELSE 0 END) as purchases_count"),
                DB::raw("COALESCE(SUM(CASE WHEN payment_status = '{$paid}' THEN total_amount ELSE 0 END), 0) as purchases_amount"),
                DB::raw('COUNT(DISTINCT client_id) as clients_count')
            )
            ->groupBy('utm_link_id')
            ->get()
            ->keyBy('utm_link_id');
    }

    /**
     * Круговая диаграмма: разрез по кол-ву клиентов на метку (решение #12).
     */
    private function buildPie(array $rows): array
    {
        $labels = [];
        $data = [];

        foreach ($rows as $row) {
            if ($row['clients'] <= 0) {
                continue;
            }
            $labels[] = $row['name'];
            $data[] = $row['clients'];
        }

        return ['labels' => $labels, 'data' => $data];
    }

    /**
     * Гистограмма: посещения по бакетам (день/месяц) с разрезом по меткам.
     */
    private function buildChart($linkIds, $links, Carbon $from, Carbon $to, Request $request): array
    {
        $rangeDays = $from->diffInDays($to) + 1;
        $granularity = $request->query('granularity');
        if (! in_array($granularity, ['day', 'month'], true)) {
            $granularity = $rangeDays <= 31 ? 'day' : 'month';
        }

        $bucketFormat = $granularity === 'day' ? '%Y-%m-%d' : '%Y-%m';

        $labels = [];
        $buckets = [];
        Carbon::setLocale('ru');
        $crossYears = $from->year !== $to->year;

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

        $series = [];

        if (! $linkIds->isEmpty()) {
            $raw = UtmVisit::query()
                ->whereIn('utm_link_id', $linkIds)
                ->whereBetween('visited_at', [$from, $to])
                ->select(
                    'utm_link_id',
                    DB::raw("DATE_FORMAT(visited_at, '{$bucketFormat}') as bucket"),
                    DB::raw('COUNT(DISTINCT visitor_hash) as unique_visits')
                )
                ->groupBy('utm_link_id', 'bucket')
                ->get();

            foreach ($links as $link) {
                $points = [];
                foreach ($buckets as $bucket) {
                    $row = $raw->first(fn ($r) => (int) $r->utm_link_id === $link->id && $r->bucket === $bucket);
                    $points[] = (int) ($row->unique_visits ?? 0);
                }
                $series[] = [
                    'link_id' => $link->id,
                    'name' => $link->name,
                    'data' => $points,
                ];
            }
        }

        return [
            'labels' => $labels,
            'series' => $series,
            'granularity' => $granularity,
        ];
    }
}
