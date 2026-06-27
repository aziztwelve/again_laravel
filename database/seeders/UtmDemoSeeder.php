<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\MarketingChannel;
use App\Models\Order;
use App\Models\UtmLink;
use App\Models\UtmTag;
use App\Models\UtmVisit;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Демо-данные для функционала «UTM-метки» (Аналитика → Источники заказов).
 *
 * Покрывает ВСЕ варианты данных, чтобы вживую проверить дашборд и метрики:
 *  - каналы: системные + созданный вручную + неактивный;
 *  - теги: используемые + неиспользуемый;
 *  - метки: активные/неактивная/soft-deleted, с тегом/без, полный/минимальный
 *    набор utm-параметров, target_url с уже существующим query (?ref=) и без;
 *  - посещения: уникальные + дубли (один visitor_hash), с referrer и без,
 *    в окне «последние 30 дней» и старые (для проверки фильтра/месячного графика);
 *  - заказы: все статусы оплаты (paid/pending/refunded/failed), привязанные к
 *    клиенту и гостевые (client_id = null), привязанные к метке и без метки,
 *    в периоде и вне периода;
 *  - граничные случаи: метка с посещениями без заказов (конв. в заказ = 0),
 *    метка с заказами без посещений (деление на 0 → 0), soft-deleted метка
 *    (в аналитику не попадает).
 *
 * ВАЖНО: сидер для dev/тестового окружения. Идемпотентен — перед наполнением
 * вычищает свои прошлые данные (заказы DEMO-*, демо-метки/посещения, демо-теги,
 * созданные вручную демо-каналы, пользователей @utm-demo.local).
 *
 * Запуск:  php artisan db:seed --class=UtmDemoSeeder
 *
 * См. docs/tasks/utm-tracking.md и docs/tasks/utm-tracking-architecture.md.
 */
class UtmDemoSeeder extends Seeder
{
    /** Фиксированные slug'и демо-меток — чтобы можно было перезапускать сидер. */
    private const DEMO_SLUGS = [
        'demoig01', 'demotg01', 'demoeml1', 'demovk01',
        'demozen1', 'demoinac', 'demodel1', 'demomin1',
    ];

    /** Имена демо-тегов (для очистки при перезапуске). */
    private const DEMO_TAGS = ['Блогер1', 'Блогер2', 'Рассылка-июнь', 'Промо-набор'];

    /** Коды демо-каналов, созданных вручную (системные не трогаем). */
    private const DEMO_CHANNEL_CODES = ['zen', 'ok'];

    /** Домен e-mail демо-пользователей (для очистки). */
    private const DEMO_EMAIL_DOMAIN = '@utm-demo.local';

    /** Сквозной счётчик для уникальных order_number вида DEMO-000001. */
    private int $orderSeq = 0;

    public function run(): void
    {
        DB::transaction(function () {
            $this->purgePreviousDemoData();

            // 1. Каналы: гарантируем системные + добавляем «ручной» и неактивный.
            $this->call(MarketingChannelSeeder::class);

            $ig = MarketingChannel::where('code', 'ig')->firstOrFail();
            $tg = MarketingChannel::where('code', 'tg')->firstOrFail();
            $vk = MarketingChannel::where('code', 'vk')->firstOrFail();
            $email = MarketingChannel::where('code', 'email')->firstOrFail();

            // Канал, созданный вручную (is_system=false — его можно удалить).
            $zen = MarketingChannel::updateOrCreate(
                ['code' => 'zen'],
                ['name' => 'Яндекс.Дзен', 'is_system' => false, 'is_active' => true, 'sort' => 10],
            );
            // Неактивный канал (демонстрирует is_active=false в справочнике).
            $ok = MarketingChannel::updateOrCreate(
                ['code' => 'ok'],
                ['name' => 'Одноклассники', 'is_system' => false, 'is_active' => false, 'sort' => 11],
            );

            // 2. Теги: три используемых + один неиспользуемый.
            $blogger1 = UtmTag::firstOrCreate(['name' => 'Блогер1']);
            $blogger2 = UtmTag::firstOrCreate(['name' => 'Блогер2']);
            $mailing = UtmTag::firstOrCreate(['name' => 'Рассылка-июнь']);
            UtmTag::firstOrCreate(['name' => 'Промо-набор']); // не привязан ни к одной метке

            // 3. Пул клиентов (для разреза круговой диаграммы «по клиентам»).
            $clients = $this->makeClients(12);

            // Периоды: основной — в окне «последние 30 дней» (период по умолчанию),
            // старый — 40–90 дней назад (для проверки фильтра и месячного графика).
            $recentStart = Carbon::now()->subDays(25)->startOfDay();
            $recentEnd = Carbon::now()->subHours(2);
            $oldStart = Carbon::now()->subDays(90)->startOfDay();
            $oldEnd = Carbon::now()->subDays(40)->endOfDay();

            // ── Метка 1: IG / Блогер1 — полный набор utm, много данных, все статусы.
            $l1 = $this->makeLink([
                'name' => 'IG Блогер1 июнь',
                'marketing_channel_id' => $ig->id,
                'utm_tag_id' => $blogger1->id,
                'target_url' => 'https://site.ru/catalog',
                'utm_source' => 'ig',
                'utm_medium' => 'cpc',
                'utm_campaign' => 'june_sale',
                'utm_content' => 'stories',
                'utm_term' => 'букет',
                'slug' => 'demoig01',
                'is_active' => true,
            ]);
            $this->seedVisits($l1->id, uniqueVisitors: 60, duplicateClicks: 30, start: $recentStart, end: $recentEnd);
            // + старые посещения (вне окна 30 дней — для пресета «Всё время»).
            $this->seedVisits($l1->id, uniqueVisitors: 20, duplicateClicks: 5, start: $oldStart, end: $oldEnd);
            $this->seedOrders($l1->id, PaymentStatus::PAID->value, 12, 5000, 14000, $clients, guestCount: 1, start: $recentStart, end: $recentEnd);
            $this->seedOrders($l1->id, PaymentStatus::PENDING->value, 3, 4000, 9000, $clients, guestCount: 1, start: $recentStart, end: $recentEnd);
            $this->seedOrders($l1->id, PaymentStatus::REFUNDED->value, 2, 6000, 11000, $clients, guestCount: 0, start: $recentStart, end: $recentEnd);
            $this->seedOrders($l1->id, PaymentStatus::FAILED->value, 1, 3000, 7000, $clients, guestCount: 1, start: $recentStart, end: $recentEnd);
            // Старый заказ (вне периода — не должен попадать в период по умолчанию).
            $this->seedOrders($l1->id, PaymentStatus::PAID->value, 1, 8000, 8000, $clients, guestCount: 0, start: $oldStart, end: $oldEnd);

            // ── Метка 2: TG / Блогер2 — target_url уже содержит query (?ref=promo).
            $l2 = $this->makeLink([
                'name' => 'TG Блогер2 июнь',
                'marketing_channel_id' => $tg->id,
                'utm_tag_id' => $blogger2->id,
                'target_url' => 'https://site.ru/sale?ref=promo',
                'utm_source' => 'tg',
                'utm_medium' => 'social',
                'utm_campaign' => 'june_sale',
                'slug' => 'demotg01',
                'is_active' => true,
            ]);
            $this->seedVisits($l2->id, uniqueVisitors: 40, duplicateClicks: 10, start: $recentStart, end: $recentEnd);
            $this->seedOrders($l2->id, PaymentStatus::PAID->value, 6, 12000, 30000, $clients, guestCount: 1, start: $recentStart, end: $recentEnd);
            $this->seedOrders($l2->id, PaymentStatus::REFUNDED->value, 1, 15000, 15000, $clients, guestCount: 0, start: $recentStart, end: $recentEnd);
            $this->seedOrders($l2->id, PaymentStatus::PENDING->value, 1, 9000, 9000, $clients, guestCount: 0, start: $recentStart, end: $recentEnd);

            // ── Метка 3: Email / без тега — только оплаченные заказы.
            $l3 = $this->makeLink([
                'name' => 'Email рассылка',
                'marketing_channel_id' => $email->id,
                'utm_tag_id' => $mailing->id,
                'target_url' => 'https://site.ru/',
                'utm_source' => 'email',
                'utm_medium' => 'newsletter',
                'slug' => 'demoeml1',
                'is_active' => true,
            ]);
            $this->seedVisits($l3->id, uniqueVisitors: 50, duplicateClicks: 8, start: $recentStart, end: $recentEnd);
            $this->seedOrders($l3->id, PaymentStatus::PAID->value, 4, 3000, 9000, $clients, guestCount: 1, start: $recentStart, end: $recentEnd);

            // ── Метка 4: VK / Блогер1 — посещения ЕСТЬ, заказов НЕТ (конв. в заказ = 0).
            $l4 = $this->makeLink([
                'name' => 'VK Блогер1 (без заказов)',
                'marketing_channel_id' => $vk->id,
                'utm_tag_id' => $blogger1->id,
                'target_url' => 'https://site.ru/catalog/roses',
                'utm_source' => 'vk',
                'slug' => 'demovk01',
                'is_active' => true,
            ]);
            $this->seedVisits($l4->id, uniqueVisitors: 25, duplicateClicks: 5, start: $recentStart, end: $recentEnd);

            // ── Метка 5: ручной канал «Дзен» — заказы ЕСТЬ, посещений НЕТ
            //    (деление на 0 в «конв. в заказ» → 0; покупки/заказы считаются).
            $l5 = $this->makeLink([
                'name' => 'Дзен статья (без посещений)',
                'marketing_channel_id' => $zen->id,
                'utm_tag_id' => null,
                'target_url' => 'https://site.ru/blog/care',
                'utm_source' => 'zen',
                'slug' => 'demozen1',
                'is_active' => true,
            ]);
            $this->seedOrders($l5->id, PaymentStatus::PAID->value, 2, 5000, 8000, $clients, guestCount: 0, start: $recentStart, end: $recentEnd);

            // ── Метка 6: НЕАКТИВНАЯ метка на неактивном канале — в аналитике видна,
            //    но /go/{slug} вернёт 404 (is_active=false).
            $l6 = $this->makeLink([
                'name' => 'OK промо (неактивна)',
                'marketing_channel_id' => $ok->id,
                'utm_tag_id' => $blogger2->id,
                'target_url' => 'https://site.ru/sale',
                'utm_source' => 'ok',
                'slug' => 'demoinac',
                'is_active' => false,
            ]);
            $this->seedVisits($l6->id, uniqueVisitors: 10, duplicateClicks: 2, start: $recentStart, end: $recentEnd);
            $this->seedOrders($l6->id, PaymentStatus::PAID->value, 1, 4000, 4000, $clients, guestCount: 0, start: $recentStart, end: $recentEnd);
            $this->seedOrders($l6->id, PaymentStatus::FAILED->value, 1, 4000, 4000, $clients, guestCount: 1, start: $recentStart, end: $recentEnd);

            // ── Метка 7: минимальный набор utm (только source) + тег «Промо-набор»
            //    был занят выше как неиспользуемый — оставляем без тега для разнообразия.
            $l7 = $this->makeLink([
                'name' => 'IG минимальная (только source)',
                'marketing_channel_id' => $ig->id,
                'utm_tag_id' => null,
                'target_url' => 'https://site.ru/new',
                'utm_source' => 'ig',
                'slug' => 'demomin1',
                'is_active' => true,
            ]);
            $this->seedVisits($l7->id, uniqueVisitors: 8, duplicateClicks: 1, start: $recentStart, end: $recentEnd);
            $this->seedOrders($l7->id, PaymentStatus::PAID->value, 1, 6000, 6000, $clients, guestCount: 0, start: $recentStart, end: $recentEnd);

            // ── Метка 8: SOFT-DELETED — не должна попадать в аналитику.
            $l8 = $this->makeLink([
                'name' => 'Удалённая метка',
                'marketing_channel_id' => $tg->id,
                'utm_tag_id' => null,
                'target_url' => 'https://site.ru/archive',
                'utm_source' => 'tg',
                'slug' => 'demodel1',
                'is_active' => true,
            ]);
            $this->seedVisits($l8->id, uniqueVisitors: 5, duplicateClicks: 0, start: $recentStart, end: $recentEnd);
            $this->seedOrders($l8->id, PaymentStatus::PAID->value, 1, 5000, 5000, $clients, guestCount: 0, start: $recentStart, end: $recentEnd);
            $l8->delete(); // soft delete

            // ── Заказ БЕЗ метки (utm_link_id = null) — не относится к UTM-аналитике.
            $this->seedOrders(null, PaymentStatus::PAID->value, 1, 4000, 4000, $clients, guestCount: 0, start: $recentStart, end: $recentEnd);

            $this->command?->info('UtmDemoSeeder: демо-данные UTM созданы. Открой Аналитика → Источники заказов.');
        });
    }

    /**
     * Удаляет данные предыдущего прогона этого сидера (идемпотентность).
     */
    private function purgePreviousDemoData(): void
    {
        // Заказы помечены префиксом DEMO- в order_number.
        Order::where('order_number', 'like', 'DEMO-%')->forceDelete();

        // Демо-метки (вместе с посещениями) — по фиксированным slug'ам.
        $links = UtmLink::withTrashed()->whereIn('slug', self::DEMO_SLUGS)->get();
        foreach ($links as $link) {
            UtmVisit::where('utm_link_id', $link->id)->delete();
            $link->forceDelete();
        }

        UtmTag::whereIn('name', self::DEMO_TAGS)->delete();

        // Только созданные вручную демо-каналы; системные не трогаем.
        MarketingChannel::whereIn('code', self::DEMO_CHANNEL_CODES)
            ->where('is_system', false)
            ->delete();

        // Демо-клиенты (по e-mail домену). Заказы DEMO-* уже удалены выше,
        // так что внешних ссылок на этих клиентов не остаётся.
        Client::where('email', 'like', '%'.self::DEMO_EMAIL_DOMAIN)->forceDelete();
    }

    /**
     * Создаёт пул клиентов и возвращает их id.
     *
     * В этой БД `clients` — самостоятельная сущность (email/password,
     * Client extends Authenticatable), без `user_id`. Обязателен только
     * `bonus_balance` (есть дефолт), поэтому достаточно задать уникальный email.
     */
    private function makeClients(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = Client::factory()->create([
                'email' => 'utm-demo-'.$i.'-'.uniqid().self::DEMO_EMAIL_DOMAIN,
            ])->id;
        }

        return $ids;
    }

    private function makeLink(array $attrs): UtmLink
    {
        return UtmLink::create($attrs);
    }

    /**
     * Пишет посещения: $uniqueVisitors уникальных (разные visitor_hash) +
     * $duplicateClicks повторных кликов существующих посетителей (тот же hash —
     * НЕ увеличивают число уникальных). Часть посещений — без referrer.
     */
    private function seedVisits(int $linkId, int $uniqueVisitors, int $duplicateClicks, Carbon $start, Carbon $end): void
    {
        $visitors = [];
        $rows = [];

        for ($i = 0; $i < $uniqueVisitors; $i++) {
            $ip = fake()->ipv4();
            $ua = fake()->userAgent();
            $visitors[] = ['ip' => $ip, 'ua' => $ua, 'hash' => hash('sha256', $ip.'|'.$ua)];
            $rows[] = $this->visitRow($linkId, end($visitors), $start, $end);
        }

        for ($i = 0; $i < $duplicateClicks && ! empty($visitors); $i++) {
            $rows[] = $this->visitRow($linkId, $visitors[array_rand($visitors)], $start, $end);
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            UtmVisit::insert($chunk);
        }
    }

    /**
     * Готовая строка для bulk-insert в utm_visits.
     */
    private function visitRow(int $linkId, array $visitor, Carbon $start, Carbon $end): array
    {
        $visitedAt = $this->randomDate($start, $end);
        $referrers = [null, 'https://t.me/', 'https://www.instagram.com/', 'https://vk.com/', null];

        return [
            'utm_link_id' => $linkId,
            'visited_at' => $visitedAt,
            'ip_address' => $visitor['ip'],
            'user_agent' => $visitor['ua'],
            'referrer' => $referrers[array_rand($referrers)],
            'visitor_hash' => $visitor['hash'],
            'created_at' => $visitedAt,
            'updated_at' => $visitedAt,
        ];
    }

    /**
     * Создаёт $count заказов с заданным статусом оплаты. $guestCount из них —
     * гостевые (client_id = null), остальные привязаны к клиентам из пула.
     */
    private function seedOrders(
        ?int $linkId,
        string $paymentStatus,
        int $count,
        int $amountMin,
        int $amountMax,
        array $clientPool,
        int $guestCount,
        Carbon $start,
        Carbon $end,
    ): void {
        $status = $this->orderStatusFor($paymentStatus);

        for ($i = 0; $i < $count; $i++) {
            $isGuest = $i < $guestCount;
            $clientId = $isGuest || empty($clientPool)
                ? null
                : $clientPool[array_rand($clientPool)];

            $createdAt = $this->randomDate($start, $end);
            $amount = (float) fake()->numberBetween($amountMin, $amountMax);

            Order::factory()->create([
                'order_number' => 'DEMO-'.str_pad((string) (++$this->orderSeq), 6, '0', STR_PAD_LEFT),
                'status' => $status,
                'payment_status' => $paymentStatus,
                'total_amount' => $amount,
                'discount_amount' => 0,
                'client_id' => $clientId,
                'utm_link_id' => $linkId,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
                'paid_at' => $paymentStatus === PaymentStatus::PAID->value ? $createdAt : null,
            ]);
        }
    }

    /**
     * Логичный статус заказа под статус оплаты (для наглядности в админке).
     */
    private function orderStatusFor(string $paymentStatus): string
    {
        return match ($paymentStatus) {
            PaymentStatus::PAID->value => OrderStatus::DELIVERED->value,
            PaymentStatus::PENDING->value => OrderStatus::NEW->value,
            PaymentStatus::REFUNDED->value => OrderStatus::PRODUCT_RETURN->value,
            PaymentStatus::FAILED->value => OrderStatus::CANCELLED->value,
            default => OrderStatus::NEW->value,
        };
    }

    private function randomDate(Carbon $start, Carbon $end): Carbon
    {
        return Carbon::createFromTimestamp(fake()->numberBetween($start->timestamp, $end->timestamp));
    }
}
