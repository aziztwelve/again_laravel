<?php

namespace Tests\Feature\Reviews;

use App\Models\Client;
use App\Models\Product;
use App\Models\Review\Review;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class PublicProductReviewsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_empty_active_product_returns_normalized_meta(): void
    {
        $product = Product::factory()->create(['is_active' => true]);

        $this->getJson($this->endpoint($product))
            ->assertOk()
            ->assertHeader('Cache-Control', 'private, no-store')
            ->assertExactJson([
                'success' => true,
                'data' => [],
                'meta' => [
                    'current_page' => 1,
                    'per_page' => 8,
                    'total' => 0,
                    'last_page' => 1,
                    'has_more' => false,
                ],
            ]);
    }

    public function test_it_paginates_only_visible_reviews_in_stable_order(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        $client = Client::factory()->create(['email' => 'public-review@example.com']);
        $client->profile()->create(['first_name' => 'Анна', 'last_name' => 'Скрытая']);

        $visible = collect(range(1, 9))->map(fn (int $number) => $this->visibleReview(
            $product,
            $client,
            ['content' => $number === 9 ? '<p>Первая<br>строка</p><script>alert(1)</script>' : "Отзыв {$number}"],
        ));

        $this->visibleReview($product, $client, ['is_spam' => true]);
        $this->visibleReview($product, $client, ['is_verified' => false]);
        $this->visibleReview(Product::factory()->create(), $client);

        $first = $this->getJson($this->endpoint($product).'?page=1&per_page=8')
            ->assertOk()
            ->assertJsonPath('meta.total', 9)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonCount(8, 'data')
            ->assertJsonMissingPath('data.0.client.email')
            ->assertJsonMissingPath('data.0.client.id')
            ->assertJsonMissingPath('data.0.is_published')
            ->json();

        $this->assertSame(
            $visible->sortByDesc('id')->take(8)->pluck('id')->values()->all(),
            collect($first['data'])->pluck('id')->all(),
        );
        $this->assertSame('Анна', $first['data'][0]['client']['name']);
        $this->assertStringNotContainsString('<', $first['data'][0]['content']);

        $this->getJson($this->endpoint($product).'?page=2&per_page=8')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_validation_and_product_visibility_are_enforced(): void
    {
        $active = Product::factory()->create(['is_active' => true]);
        $inactive = Product::factory()->create(['is_active' => false]);
        $deleted = Product::factory()->create(['is_active' => true]);
        $deleted->delete();

        foreach (['page=0', 'page=nope', 'per_page=0', 'per_page=21', 'per_page=nope'] as $query) {
            $this->getJson($this->endpoint($active).'?'.$query)->assertUnprocessable();
        }

        $this->getJson($this->endpoint($inactive))->assertNotFound();
        $this->getJson('/api/public/catalog/products/'.$deleted->id.'/reviews')->assertNotFound();
        $this->getJson('/api/public/catalog/products/999999999/reviews')->assertNotFound();
    }

    private function endpoint(Product $product): string
    {
        return '/api/public/catalog/products/'.$product->id.'/reviews';
    }

    private function visibleReview(Product $product, Client $client, array $overrides = []): Review
    {
        $review = Review::factory()->create(array_merge([
            'client_id' => $client->id,
            'reviewable_type' => Product::class,
            'reviewable_id' => $product->id,
            'content' => 'Отличный товар',
            'rating' => 5,
        ], $overrides));

        $review->update(array_merge([
            'is_published' => true,
            'is_verified' => true,
            'is_spam' => false,
            'published_at' => now()->startOfSecond(),
            'status' => Review::STATUS_PUBLISHED,
        ], $overrides));

        return $review->refresh();
    }
}
