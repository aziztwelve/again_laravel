<?php

namespace App\Console\Commands;

use App\Support\PublicUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Переписывает сохранённые в БД ссылки с прежних доменов проекта на актуальный.
 *
 * Список прежних хостов берётся из LEGACY_HOSTS (config('app.legacy_hosts')),
 * целевой домен — из APP_URL/FRONTEND_URL. Ничего не зашито в код, команда
 * идемпотентна: повторный запуск не меняет уже исправленные строки.
 */
class CanonicalizeStoredUrls extends Command
{
    protected $signature = 'urls:canonicalize
        {--dry-run : Показать, что будет изменено, без записи}';

    protected $description = 'Перевести ссылки в БД с прежних доменов (LEGACY_HOSTS) на актуальный';

    /**
     * Колонки, которые содержат ссылки на собственные страницы/файлы.
     * Историческую переписку (messages.content, vk_webhook_events.data)
     * сознательно не трогаем: это архив, а не рабочие ссылки.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const TARGETS = [
        ['utm_links', 'target_url'],
        ['images', 'url'],
        ['message_attachments', 'url'],
    ];

    public function handle(): int
    {
        $legacyHosts = PublicUrl::legacyHosts();

        if (! $legacyHosts) {
            $this->warn('LEGACY_HOSTS не задан — нечего переписывать.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->line('Прежние хосты: '.implode(', ', $legacyHosts));
        $this->line('Актуальный хост: '.PublicUrl::host());
        $this->newLine();

        $total = 0;

        foreach (self::TARGETS as [$table, $column]) {
            if (! DB::getSchemaBuilder()->hasTable($table)) {
                $this->warn("{$table}: таблицы нет, пропускаю");

                continue;
            }

            $changed = 0;
            $seen = [];

            foreach ($legacyHosts as $host) {
                DB::table($table)
                    ->where($column, 'like', '%'.$host.'%')
                    ->orderBy('id')
                    ->select(['id', $column])
                    ->chunkById(500, function ($rows) use ($table, $column, $dryRun, &$changed, &$seen) {
                        foreach ($rows as $row) {
                            // Хосты из LEGACY_HOSTS пересекаются как подстроки
                            // (`old.example.com` ⊂ `sub.old.example.com`), поэтому
                            // считаем каждую строку один раз.
                            if (isset($seen[$row->id])) {
                                continue;
                            }

                            $current = (string) $row->{$column};
                            $canonical = (string) PublicUrl::canonicalize($current);

                            if ($canonical === $current) {
                                continue;
                            }

                            $seen[$row->id] = true;
                            $changed++;

                            if ($this->output->isVerbose()) {
                                $this->line("  #{$row->id}: {$current} -> {$canonical}");
                            }

                            if (! $dryRun) {
                                DB::table($table)->where('id', $row->id)->update([$column => $canonical]);
                            }
                        }
                    });
            }

            $total += $changed;
            $this->line(sprintf('%s.%s: %d %s', $table, $column, $changed, $dryRun ? 'к изменению' : 'обновлено'));
        }

        $this->newLine();
        $this->info($dryRun
            ? "Всего к изменению: {$total} (запуск без --dry-run применит правки)"
            : "Всего обновлено: {$total}");

        return self::SUCCESS;
    }
}
