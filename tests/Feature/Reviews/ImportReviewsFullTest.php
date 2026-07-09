<?php

namespace Tests\Feature\Reviews;

use App\Models\Client;
use App\Models\Product;
use App\Models\Review\Review;
use App\Models\Review\ReviewResponse;
use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Тесты команды import:reviews:full (docs/tasks/reviews-full-import.md).
 */
class ImportReviewsFullTest extends TestCase
{
    use DatabaseTransactions;

    private string $csvPath;

    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\MailSetting::create([
            'mailer' => 'smtp',
            'host' => 'localhost',
            'port' => 25,
            'username' => 'test',
            'password' => 'secret',
            'from_address' => 'test@example.com',
        ]);

        // Пользователь-админ для ответов менеджера (user_id=1 по умолчанию).
        if (! User::find(1)) {
            User::factory()->create(['id' => 1]);
        }

        $this->csvPath = sys_get_temp_dir().'/reviews_test_'.uniqid().'.csv';
    }

    protected function tearDown(): void
    {
        if (isset($this->csvPath) && is_file($this->csvPath)) {
            @unlink($this->csvPath);
        }
        parent::tearDown();
    }

    /**
     * Пишет CSV (UTF-8 tab-separated) с нужными строками. Заголовок фиксирован.
     *
     * @param  array<int,array<string,string>>  $rows
     */
    private function writeCsv(array $rows): void
    {
        $header = [
            'Дата и время публикации', 'Имя автора', 'E-mail автора', 'Название Товара',
            'ID Товара', 'Артикул Товара', 'Ссылка на Товар', 'Комментарий', 'Рейтинг',
            'Ответ менеджера', 'Имя менеджера', 'Дата и время ответа', 'Опубликованность', 'Спам',
        ];

        $lines = [implode("\t", $header)];
        foreach ($rows as $row) {
            $line = [];
            foreach ($header as $col) {
                $line[] = str_replace(["\t", "\n"], ' ', $row[$col] ?? '');
            }
            $lines[] = implode("\t", $line);
        }

        file_put_contents($this->csvPath, implode("\r\n", $lines));
    }

    private function makeProduct(string $name, bool $active, ?string $slug = null): Product
    {
        return Product::factory()->create([
            'name' => $name,
            'slug' => $slug,
            'is_active' => $active,
        ]);
    }

    private function baseRow(array $overrides = []): array
    {
        return array_merge([
            'Дата и время публикации' => '2024-05-19 18:36:28',
            'Имя автора' => 'Лилия',
            'E-mail автора' => 'lily@example.com',
            'Название Товара' => 'Love AGAIN',
            'ID Товара' => '434714902',
            'Артикул Товара' => 'again-love-черный-xs',
            'Ссылка на Товар' => '/product/love-again',
            'Комментарий' => 'Отличное качество, очень довольна покупкой!',
            'Рейтинг' => '5',
            'Ответ менеджера' => '',
            'Имя менеджера' => '',
            'Дата и время ответа' => '',
            'Опубликованность' => 'Да',
            'Спам' => 'Нет',
        ], $overrides);
    }

    public function test_imports_review_and_creates_guest_client(): void
    {
        $product = $this->makeProduct('Менструальные трусы Love AGAIN', true);

        $this->writeCsv([$this->baseRow()]);

        $this->artisan('import:reviews:full', ['path' => $this->csvPath])
            ->assertSuccessful();

        $client = Client::where('email', 'lily@example.com')->first();
        $this->assertNotNull($client);
        $this->assertSame('Лилия', $client->profile?->first_name);

        $review = Review::where('reviewable_id', $product->id)->first();
        $this->assertNotNull($review);
        $this->assertSame($client->id, $review->client_id);
        $this->assertSame(5, $review->rating);
        $this->assertTrue($review->is_published);
        $this->assertTrue($review->is_verified);
        $this->assertSame(Review::STATUS_PUBLISHED, $review->status);
        $this->assertNotNull($review->published_at);
        $this->assertSame('2024-05-19', $review->created_at->toDateString());
    }

    public function test_is_idempotent_on_reruns(): void
    {
        $this->makeProduct('Менструальные трусы Save AGAIN', true);

        $this->writeCsv([$this->baseRow([
            'Название Товара' => 'Save AGAIN',
            'E-mail автора' => 'dup@example.com',
            'Комментарий' => 'Не протекает, очень удобно носить весь день.',
        ])]);

        $this->artisan('import:reviews:full', ['path' => $this->csvPath])->assertSuccessful();
        $this->artisan('import:reviews:full', ['path' => $this->csvPath])->assertSuccessful();

        $this->assertSame(1, Review::where('client_id', Client::where('email', 'dup@example.com')->value('id'))->count());
        $this->assertSame(1, Client::where('email', 'dup@example.com')->count());
    }

    public function test_skips_rows_without_rating(): void
    {
        $product = $this->makeProduct('Менструальные трусы Love AGAIN', true);

        $this->writeCsv([
            $this->baseRow(['Рейтинг' => '', 'Комментарий' => 'Без рейтинга']),
            $this->baseRow(['Рейтинг' => '0', 'Комментарий' => 'Ноль рейтинг']),
            $this->baseRow(['Рейтинг' => '5', 'Комментарий' => 'С рейтингом']),
        ]);

        $this->artisan('import:reviews:full', ['path' => $this->csvPath])->assertSuccessful();

        $this->assertSame(1, Review::where('reviewable_id', $product->id)->count());
    }

    public function test_skips_unmapped_products(): void
    {
        $this->makeProduct('Менструальные трусы Love AGAIN', true);

        $this->writeCsv([
            $this->baseRow(['Название Товара' => 'Comfort AGAIN (бесшовные)', 'Комментарий' => 'Комфорт']),
        ]);

        $this->artisan('import:reviews:full', ['path' => $this->csvPath])->assertSuccessful();

        $this->assertSame(0, Review::count());
    }

    public function test_unpublished_row_is_not_published(): void
    {
        $product = $this->makeProduct('Менструальные трусы Passion AGAIN', true);

        $this->writeCsv([$this->baseRow([
            'Название Товара' => 'Passion AGAIN',
            'Опубликованность' => 'Нет',
            'Комментарий' => 'Скрытый отзыв, не публиковать.',
        ])]);

        $this->artisan('import:reviews:full', ['path' => $this->csvPath])->assertSuccessful();

        $review = Review::where('reviewable_id', $product->id)->first();
        $this->assertNotNull($review);
        $this->assertFalse($review->is_published);
        $this->assertSame(Review::STATUS_NEW, $review->status);
        $this->assertNull($review->published_at);
    }

    public function test_stores_manager_response(): void
    {
        $product = $this->makeProduct('Менструальные трусы Body AGAIN', true);

        $this->writeCsv([$this->baseRow([
            'Название Товара' => 'Body AGAIN',
            'Комментарий' => 'Хороший товар, спасибо.',
            'Ответ менеджера' => 'Спасибо за отзыв, рады обратной связи!',
            'Имя менеджера' => 'ЧЕБОТАЕВА ТАТЬЯНА',
            'Дата и время ответа' => '2024-05-20 09:07:54',
        ])]);

        $this->artisan('import:reviews:full', ['path' => $this->csvPath])->assertSuccessful();

        $review = Review::where('reviewable_id', $product->id)->first();
        $this->assertNotNull($review);
        $response = ReviewResponse::where('review_id', $review->id)->first();
        $this->assertNotNull($response);
        $this->assertSame('Спасибо за отзыв, рады обратной связи!', $response->content);
        $this->assertSame(1, $response->user_id);

        // Повторный запуск не дублирует ответ.
        $this->artisan('import:reviews:full', ['path' => $this->csvPath])->assertSuccessful();
        $this->assertSame(1, ReviewResponse::where('review_id', $review->id)->count());
    }

    public function test_reattaches_reviews_from_inactive_duplicate_to_active(): void
    {
        // Неактивный дубль со старым отзывом + активный товар той же группы.
        $inactive = $this->makeProduct('Менструальные трусы Sexy AGAIN', false, 'menstrualnye-trusy-sexy-again');
        $active = $this->makeProduct('Менструальные трусы Sexy AGAIN', true, 'menstrualnye-trusy-sexy-again-1');

        $client = Client::factory()->create(['email' => 'old@example.com']);
        UserProfile::create(['client_id' => $client->id, 'first_name' => 'Старый']);

        $old = Review::create([
            'client_id' => $client->id,
            'reviewable_type' => Product::class,
            'reviewable_id' => $inactive->id,
            'content' => 'Старый отзыв на неактивном дубле',
            'rating' => 5,
        ]);

        $this->writeCsv([]); // только перепривязка

        $this->artisan('import:reviews:full', ['path' => $this->csvPath])->assertSuccessful();

        $old->refresh();
        $this->assertSame($active->id, $old->reviewable_id);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->makeProduct('Менструальные трусы Love AGAIN', true);

        $this->writeCsv([$this->baseRow(['E-mail автора' => 'dry@example.com'])]);

        $this->artisan('import:reviews:full', ['path' => $this->csvPath, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, Review::count());
        $this->assertNull(Client::where('email', 'dry@example.com')->first());
    }
}
