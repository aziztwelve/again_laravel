<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\YandexOrder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class YandexDeliveryAnalyticsController extends Controller
{
    public function summary(): JsonResponse
    {
        $orders = YandexOrder::query()->with('statusEvents')->get();
        $delivered = $orders->where('internal_status', 'delivered');
        $durations = $delivered->map(function (YandexOrder $order) {
            $event = $order->statusEvents->where('internal_status', 'delivered')->sortByDesc('received_at')->first();
            return $event?->received_at ? $order->created_at->diffInHours($event->received_at) : null;
        })->filter();

        return response()->json([
            'total' => $orders->count(),
            'delivered' => $delivered->count(),
            'cancelled' => $orders->where('internal_status', 'cancelled')->count(),
            'failed' => $orders->where('internal_status', 'failed')->count(),
            'returning' => $orders->where('internal_status', 'returning')->count(),
            'delivery_cost_total' => round((float) $orders->sum('price'), 2),
            'success_rate' => $orders->isEmpty() ? 0 : round($delivered->count() / $orders->count() * 100, 1),
            'average_delivery_hours' => $durations->isEmpty() ? null : round($durations->avg(), 1),
        ]);
    }

    public function export(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Заказ', 'Трек-номер', 'Статус', 'Тип', 'Стоимость', 'Создана', 'Обновлена'], ';');
            YandexOrder::query()->with('order')->orderByDesc('id')->chunkById(200, function ($orders) use ($out): void {
                foreach ($orders as $delivery) {
                    fputcsv($out, [
                        $delivery->order?->order_number ?? $delivery->order_id,
                        $delivery->claim_id,
                        $delivery->internal_status,
                        $delivery->delivery_type,
                        $delivery->price,
                        optional($delivery->created_at)->format('Y-m-d H:i:s'),
                        optional($delivery->last_synced_at)->format('Y-m-d H:i:s'),
                    ], ';');
                }
            });
            fclose($out);
        }, 'yandex-delivery-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
