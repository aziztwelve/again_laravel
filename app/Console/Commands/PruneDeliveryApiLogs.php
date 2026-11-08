<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Чистка аудит-логов обращений к API СДЭК и Яндекс.Доставки.
 *
 * Удаляются только строки этих двух таблиц: успешные запросы старше
 * API_LOG_RETENTION_DAYS (30), запросы с ошибкой — старше
 * API_LOG_ERROR_RETENTION_DAYS (90). Заказы, платежи, статусы и история заказов
 * не затрагиваются. Удаление идёт небольшими пачками, чтобы не нагружать базу.
 */
class PruneDeliveryApiLogs extends Command
{
    protected $signature = 'delivery-logs:prune {--dry-run : Только показать, сколько строк будет удалено}';

    protected $description = 'Delete old rows from cdek_api_logs and yandex_api_logs (success: 30 days, errors: 90 days)';

    private const TABLES = ['cdek_api_logs', 'yandex_api_logs'];

    private const BATCH = 500;

    public function handle(): int
    {
        $okDays = max(1, (int) config('services.delivery_api_logs.retention_days', 30));
        $errorDays = max($okDays, (int) config('services.delivery_api_logs.error_retention_days', 90));
        $dryRun = (bool) $this->option('dry-run');

        foreach (self::TABLES as $table) {
            $query = fn () => DB::table($table)->where(function ($q) use ($okDays, $errorDays) {
                $q->where(fn ($ok) => $ok->where('is_error', false)->where('created_at', '<', now()->subDays($okDays)))
                    ->orWhere(fn ($err) => $err->where('is_error', true)->where('created_at', '<', now()->subDays($errorDays)));
            });

            if ($dryRun) {
                $this->line(sprintf('%s: would delete %d rows', $table, $query()->count()));

                continue;
            }

            $deleted = 0;
            do {
                $ids = $query()->orderBy('id')->limit(self::BATCH)->pluck('id');
                if ($ids->isEmpty()) {
                    break;
                }
                $deleted += DB::table($table)->whereIn('id', $ids)->delete();
                usleep(200_000);
            } while ($ids->count() === self::BATCH);

            $this->line(sprintf('%s: deleted %d rows', $table, $deleted));
        }

        return self::SUCCESS;
    }
}
