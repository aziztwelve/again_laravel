<?php

namespace App\Console\Commands\Import;

use App\Models\Client;
use App\Models\Product;
use App\Models\Review\Review;
use App\Models\Review\ReviewResponse;
use App\Models\UserProfile;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Полный идемпотентный импорт отзывов из CSV-выгрузки на сайт.
 * См. docs/tasks/reviews-full-import.md (решения #1–#11).
 *
 * Отличия от старой import:reviews:
 *  - Динамический маппинг CSV-названия → активный товар витрины (учёт дублей).
 *  - Перепривязка старых отзывов с неактивных/удалённых дублей на активный товар.
 *  - Идемпотентность (повторный запуск не плодит отзывы/клиентов/ответы).
 *  - Гостевые клиенты создаются по email (решение #4).
 *  - Пустой рейтинг и несопоставленный товар — пропуск (решения #5, #11).
 *  - Кодировка UTF-16 LE + ISO/д.м.г даты.
 *  - Режим --dry-run: только сводка, без записи.
 */
class ImportReviewsFull extends Command
{
    protected $signature = 'import:reviews:full
        {path? : Путь к CSV (по умолчанию storage/imports/reviews-15.05.2026.csv)}
        {--dry-run : Только показать сводку, ничего не писать в БД}
        {--manager-user-id=1 : ID пользователя-админа для ответов менеджера}';

    protected $description = 'Полный идемпотентный импорт отзывов из CSV с перепривязкой на активные товары';

    /**
     * CSV «Название Товара» → поисковый фрагмент имени товара в БД.
     * Ссылки/артикулы из CSV устарели, матчим по имени AGAIN-линейки.
     */
    protected array $productNameMap = [
        'Save AGAIN' => 'Save AGAIN',
        'Love AGAIN' => 'Love AGAIN',
        'Passion AGAIN' => 'Passion AGAIN',
        'Sexy AGAIN' => 'Sexy AGAIN',
        'Body AGAIN' => 'Body AGAIN',
        'BOX AGAIN' => 'BOX бель',
        'Любимый SET от доктора Садовская' => 'Любимый SET',
        'LOVE SET' => 'LOVE SET',
    ];

    /** Кэш: поисковый фрагмент → каноничный активный товар (или null). */
    protected array $canonicalCache = [];

    protected bool $dryRun = false;

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $path = $this->argument('path') ?: storage_path('imports/reviews-15.05.2026.csv');

        if (! is_file($path)) {
            $this->error("❌ Файл не найден: {$path}");

            return self::FAILURE;
        }

        $this->info(($this->dryRun ? '🧪 [DRY-RUN] ' : '🚀 ')."Импорт отзывов из {$path}");

        // 1. Перепривязка старых отзывов на каноничные активные товары (решение #2).
        $reattached = $this->reattachOldReviews();

        // 2. Импорт строк CSV.
        $rows = $this->readCsv($path);
        if ($rows === null) {
            return self::FAILURE;
        }

        $stat = [
            'total' => count($rows),
            'imported' => 0,
            'duplicates' => 0,
            'skipped_no_rating' => 0,
            'skipped_no_product' => 0,
            'clients_created' => 0,
            'responses_created' => 0,
            'response_duplicates' => 0,
            'errors' => 0,
        ];

        $bar = $this->output->createProgressBar($stat['total']);
        $bar->start();

        foreach ($rows as $row) {
            try {
                $this->importRow($row, $stat);
            } catch (\Throwable $e) {
                $stat['errors']++;
                logger()->error('import:reviews:full row error', [
                    'error' => $e->getMessage(),
                    'row' => $row,
                ]);
            }
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line('── Сводка ──');
        $this->line("Перепривязано старых отзывов:  <fg=cyan>{$reattached}</>");
        $this->line("Всего строк CSV:               {$stat['total']}");
        $this->line("Импортировано (новых):         <fg=green>{$stat['imported']}</>");
        $this->line("Дубли (уже в БД, пропущены):   <fg=yellow>{$stat['duplicates']}</>");
        $this->line("Пропущено — нет рейтинга:      <fg=yellow>{$stat['skipped_no_rating']}</>");
        $this->line("Пропущено — нет товара:        <fg=yellow>{$stat['skipped_no_product']}</>");
        $this->line("Создано гостевых клиентов:     <fg=green>{$stat['clients_created']}</>");
        $this->line("Ответов менеджера создано:     <fg=green>{$stat['responses_created']}</>");
        $this->line("Ответов-дублей пропущено:      {$stat['response_duplicates']}");
        $this->line("Ошибок:                        <fg=red>{$stat['errors']}</>");

        if ($this->dryRun) {
            $this->newLine();
            $this->warn('DRY-RUN: изменения НЕ сохранены. Запустите без --dry-run для реального импорта.');
        }

        return self::SUCCESS;
    }

    /**
     * Перепривязать отзывы с неактивных/удалённых дублей на каноничный
     * активный товар той же AGAIN-группы. Идемпотентно.
     *
     * @return int число перепривязанных отзывов
     */
    protected function reattachOldReviews(): int
    {
        $moved = 0;

        foreach (array_unique(array_values($this->productNameMap)) as $term) {
            $canonical = $this->resolveCanonicalProduct($term);
            if (! $canonical) {
                continue;
            }

            $groupIds = Product::withTrashed()
                ->where('name', 'like', '%'.$term.'%')
                ->pluck('id')
                ->all();

            $sourceIds = array_values(array_diff($groupIds, [$canonical->id]));
            if (empty($sourceIds)) {
                continue;
            }

            $count = Review::withTrashed()
                ->where('reviewable_type', Product::class)
                ->whereIn('reviewable_id', $sourceIds)
                ->count();

            if ($count === 0) {
                continue;
            }

            $this->line("  Перепривязка «{$term}»: {$count} отз. → товар #{$canonical->id}");

            if (! $this->dryRun) {
                Review::withTrashed()
                    ->where('reviewable_type', Product::class)
                    ->whereIn('reviewable_id', $sourceIds)
                    ->update(['reviewable_id' => $canonical->id]);
            }

            $moved += $count;
        }

        return $moved;
    }

    protected function importRow(array $data, array &$stat): void
    {
        // Рейтинг обязателен (решение #5 — иначе пропуск).
        $rating = trim((string) ($data['Рейтинг'] ?? ''));
        if ($rating === '' || ! ctype_digit($rating) || (int) $rating < 1 || (int) $rating > 5) {
            $stat['skipped_no_rating']++;

            return;
        }
        $rating = (int) $rating;

        // Товар: маппинг CSV-названия → каноничный активный товар (решения #1, #11).
        $csvName = trim((string) ($data['Название Товара'] ?? ''));
        $product = $this->resolveProductForCsvName($csvName);
        if (! $product) {
            $stat['skipped_no_product']++;

            return;
        }

        $content = trim((string) ($data['Комментарий'] ?? ''));
        if ($content === '') {
            $stat['skipped_no_rating']++; // трактуем пустой текст как непригодный

            return;
        }

        $email = mb_strtolower(trim((string) ($data['E-mail автора'] ?? '')));
        $authorName = trim((string) ($data['Имя автора'] ?? ''));

        // Клиент: существующий по email или новый гостевой (решение #4).
        $client = $this->resolveClient($email, $authorName, $stat);
        if (! $client) {
            // dry-run: клиента ещё нет — считаем как будущий импорт (клиент создастся).
            $stat['imported']++;
            $this->maybeCountResponse($data, $stat, null);

            return;
        }

        // Идемпотентность (решение #3): тот же товар + клиент + нормализованный текст.
        if ($this->reviewExists($product->id, $client->id, $content)) {
            $stat['duplicates']++;
            $this->maybeCountResponse($data, $stat, $this->findExistingReview($product->id, $client->id, $content));

            return;
        }

        $publishedAt = $this->parseDate($data['Дата и время публикации'] ?? '') ?? now();
        $isPublished = trim((string) ($data['Опубликованность'] ?? '')) === 'Да';
        $isSpam = trim((string) ($data['Спам'] ?? '')) === 'Да';

        if ($this->dryRun) {
            $stat['imported']++;
            $this->maybeCountResponse($data, $stat, null);

            return;
        }

        $review = new Review;
        $review->client_id = $client->id;
        $review->reviewable_type = Product::class;
        $review->reviewable_id = $product->id;
        $review->content = $content;
        $review->rating = $rating;
        $review->is_spam = $isSpam;
        $review->created_at = $publishedAt;
        $review->updated_at = $publishedAt;
        $review->save(); // boot creating-хук форсит unpublished

        if ($isPublished) {
            // Публикуем отдельным save — сработает updating-хук (status=published),
            // published_at выставляем явно, чтобы не затёрлось now().
            $review->is_published = true;
            $review->is_verified = true;
            $review->published_at = $publishedAt;
            $review->save();
        }

        $stat['imported']++;

        $this->storeManagerResponse($data, $review, $stat);
    }

    /**
     * Ответ менеджера (решение #7). Идемпотентно по тексту ответа.
     */
    protected function storeManagerResponse(array $data, Review $review, array &$stat): void
    {
        $answer = trim((string) ($data['Ответ менеджера'] ?? ''));
        if ($answer === '') {
            return;
        }

        $exists = $review->responses()
            ->where('content', $answer)
            ->exists();

        if ($exists) {
            $stat['response_duplicates']++;

            return;
        }

        $respondedAt = $this->parseDate($data['Дата и время ответа'] ?? '') ?? now();

        ReviewResponse::create([
            'review_id' => $review->id,
            'user_id' => (int) $this->option('manager-user-id'),
            'content' => $answer,
            'is_published' => true,
            'created_at' => $respondedAt,
            'updated_at' => $respondedAt,
        ]);

        $stat['responses_created']++;
    }

    /**
     * Учёт ответа менеджера в сводке для dry-run / дублей (без записи).
     */
    protected function maybeCountResponse(array $data, array &$stat, ?Review $review): void
    {
        $answer = trim((string) ($data['Ответ менеджера'] ?? ''));
        if ($answer === '') {
            return;
        }

        if ($review && $review->responses()->where('content', $answer)->exists()) {
            $stat['response_duplicates']++;

            return;
        }

        $stat['responses_created']++;
    }

    /**
     * Существующий клиент по email или новый гостевой (с профилем-именем).
     * dry-run: не создаём, возвращаем найденного или null.
     */
    protected function resolveClient(string $email, string $authorName, array &$stat): ?Client
    {
        if ($email === '') {
            // email в CSV всегда есть, но подстрахуемся.
            $email = 'noemail+'.md5($authorName.microtime()).'@import.local';
        }

        $client = Client::where('email', $email)->first();

        if ($client) {
            // У существующего клиента без профиля — добавим имя, чтобы отзыв
            // отображал автора (не трогаем существующий профиль).
            if (! $this->dryRun && ! $client->profile && $authorName !== '') {
                $this->createProfile($client->id, $authorName);
            }

            return $client;
        }

        if ($this->dryRun) {
            return null; // клиент создастся при реальном запуске
        }

        $client = Client::create([
            'email' => $email,
            'personal_data_consent' => false,
            'subscribed_to_newsletter' => false,
        ]);

        if ($authorName !== '') {
            $this->createProfile($client->id, $authorName);
        }

        $stat['clients_created']++;

        return $client;
    }

    protected function createProfile(int $clientId, string $authorName): void
    {
        // Имя автора в CSV — как правило одно слово; кладём целиком в first_name.
        UserProfile::create([
            'client_id' => $clientId,
            'first_name' => mb_substr($authorName, 0, 255),
        ]);
    }

    protected function reviewExists(int $productId, int $clientId, string $content): bool
    {
        return $this->findExistingReview($productId, $clientId, $content) !== null;
    }

    protected function findExistingReview(int $productId, int $clientId, string $content): ?Review
    {
        $needle = $this->normalize($content);

        return Review::withTrashed()
            ->where('reviewable_type', Product::class)
            ->where('reviewable_id', $productId)
            ->where('client_id', $clientId)
            ->get()
            ->first(fn (Review $r) => $this->normalize((string) $r->content) === $needle);
    }

    protected function normalize(string $s): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
    }

    protected function resolveProductForCsvName(string $csvName): ?Product
    {
        $term = $this->productNameMap[$csvName] ?? null;
        if ($term === null) {
            return null; // несопоставленный товар (Comfort/Soft/…): пропуск
        }

        return $this->resolveCanonicalProduct($term);
    }

    /**
     * Каноничный активный товар группы: активный (не soft-deleted) с наибольшим
     * числом отзывов; при равенстве — наименьший id. Кэшируется.
     */
    protected function resolveCanonicalProduct(string $term): ?Product
    {
        if (array_key_exists($term, $this->canonicalCache)) {
            return $this->canonicalCache[$term];
        }

        $candidates = Product::where('name', 'like', '%'.$term.'%')
            ->where('is_active', true)
            ->get();

        $best = null;
        $bestCount = -1;
        foreach ($candidates as $p) {
            $count = Review::withTrashed()
                ->where('reviewable_type', Product::class)
                ->where('reviewable_id', $p->id)
                ->count();

            if ($count > $bestCount || ($count === $bestCount && $best && $p->id < $best->id)) {
                $best = $p;
                $bestCount = $count;
            }
        }

        return $this->canonicalCache[$term] = $best;
    }

    /**
     * Чтение CSV: UTF-16 LE/BE или UTF-8, tab-separated, многострочные поля.
     *
     * @return array<int,array<string,string>>|null
     */
    protected function readCsv(string $path): ?array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->error('❌ Не удалось прочитать файл');

            return null;
        }

        $prefix = substr($raw, 0, 2);
        if ($prefix === "\xFF\xFE") {
            $utf8 = mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
        } elseif ($prefix === "\xFE\xFF") {
            $utf8 = mb_convert_encoding($raw, 'UTF-8', 'UTF-16BE');
        } else {
            $utf8 = $raw;
        }
        // Срезаем возможный UTF-8 BOM.
        $utf8 = preg_replace('/^\x{FEFF}/u', '', $utf8);

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $utf8);
        rewind($fh);

        $header = fgetcsv($fh, 0, "\t");
        if (! $header) {
            $this->error('❌ Пустой заголовок CSV');
            fclose($fh);

            return null;
        }
        $header = array_map(fn ($h) => trim((string) $h), $header);

        $rows = [];
        while (($row = fgetcsv($fh, 0, "\t")) !== false) {
            if ($row === [null] || (count($row) === 1 && trim((string) $row[0]) === '')) {
                continue; // пустая строка
            }
            if (count($row) !== count($header)) {
                continue; // битая строка — пропуск
            }
            $rows[] = array_combine($header, $row);
        }
        fclose($fh);

        return $rows;
    }

    protected function parseDate(?string $s): ?Carbon
    {
        $s = trim((string) $s);
        if ($s === '') {
            return null;
        }

        // ISO «2024-05-19 18:36:28» и прочее — через Carbon::parse.
        try {
            return Carbon::parse($s);
        } catch (\Throwable) {
        }

        // Старый формат «19.05.2024 18:36».
        try {
            return Carbon::createFromFormat('d.m.Y H:i', $s);
        } catch (\Throwable) {
            return null;
        }
    }
}
