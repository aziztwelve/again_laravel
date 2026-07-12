<?php

namespace Tests\Feature\Reviews;

use App\Models\Client;
use App\Models\Product;
use App\Models\Review\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublicProductReviewsTest extends TestCase
{
    use DatabaseTransactions;

    public function test_empty_active_product_returns_normalized_meta(): void
    {
        $product = Product::factory()->create(['is_active' => true]);

        $response = $this->getJson($this->endpoint($product))
            ->assertOk()
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

        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
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

    public function test_query_count_does_not_scale_with_page_size(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $client->profile()->create(['first_name' => 'Query', 'last_name' => 'Count']);

        foreach (range(1, 20) as $number) {
            $this->visibleReview($product, $client, ['content' => "Отзыв {$number}"]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->endpoint($product).'?per_page=1')->assertOk();
        $singleReviewQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->getJson($this->endpoint($product).'?per_page=20')->assertOk();
        $twentyReviewQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            $singleReviewQueries + 1,
            $twentyReviewQueries,
            "Query count grew from {$singleReviewQueries} to {$twentyReviewQueries}",
        );
    }

    public function test_admin_mode_requires_a_staff_actor(): void
    {
        $this->getJson('/api/reviews?admin=true')->assertUnauthorized();

        Sanctum::actingAs(Client::factory()->create());
        $this->getJson('/api/reviews?admin=true')->assertForbidden();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/reviews?admin=true')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_client_can_create_and_like_only_public_product_reviews(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        $inactiveProduct = Product::factory()->create(['is_active' => false]);
        $client = Client::factory()->create();
        $visible = $this->visibleReview($product, $client);
        $hidden = $this->visibleReview($product, $client);
        $hidden->update(['is_published' => false, 'published_at' => null]);
        Sanctum::actingAs($client);

        $createdId = $this->postJson('/api/reviews', [
            'reviewable_id' => $product->id,
            'reviewable_type' => 'Product',
            'content' => 'Новый отзыв для проверки модерации',
            'rating' => 5,
        ])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('reviews', [
            'id' => $createdId,
            'client_id' => $client->id,
            'is_published' => false,
        ]);

        $this->postJson('/api/reviews', [
            'reviewable_id' => $inactiveProduct->id,
            'reviewable_type' => 'Product',
            'content' => 'Отзыв неактивного товара',
            'rating' => 5,
        ])->assertNotFound();

        $this->postJson("/api/reviews/{$visible->id}/like")
            ->assertOk()->assertJsonPath('success', true);
        $this->postJson("/api/reviews/{$visible->id}/like")
            ->assertOk()->assertJsonPath('success', true);
        $this->deleteJson("/api/reviews/{$visible->id}/unlike")
            ->assertOk()->assertJsonPath('success', true);
        $this->postJson("/api/reviews/{$hidden->id}/like")->assertNotFound();

        foreach (['publish', 'unpublish'] as $action) {
            $this->postJson("/api/reviews/{$visible->id}/{$action}")->assertForbidden();
        }
        $this->deleteJson("/api/reviews/{$visible->id}")->assertForbidden();
    }

    public function test_staff_cannot_create_or_like_but_can_moderate(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        $client = Client::factory()->create();
        $review = Review::factory()->create([
            'client_id' => $client->id,
            'reviewable_type' => Product::class,
            'reviewable_id' => $product->id,
        ]);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/reviews', [
            'reviewable_id' => $product->id,
            'reviewable_type' => 'Product',
            'content' => 'Сотрудник не может создать отзыв',
            'rating' => 5,
        ])->assertForbidden();
        $this->postJson("/api/reviews/{$review->id}/like")->assertForbidden();
        $this->postJson("/api/reviews/{$review->id}/publish")->assertOk();
        $this->assertTrue($review->refresh()->is_published);
        $this->postJson("/api/reviews/{$review->id}/unpublish")->assertOk();
        $this->assertFalse($review->refresh()->is_published);
        $this->deleteJson("/api/reviews/{$review->id}")->assertNoContent();
    }

    public function test_personal_like_state_is_isolated_between_clients(): void
    {
        $product = Product::factory()->create(['is_active' => true]);
        $owner = Client::factory()->create();
        $firstViewer = Client::factory()->create();
        $secondViewer = Client::factory()->create();
        $review = $this->visibleReview($product, $owner);

        Sanctum::actingAs($firstViewer);
        $this->postJson("/api/reviews/{$review->id}/like")->assertOk();
        $first = $this->getJson($this->endpoint($product))->assertOk();
        $this->assertTrue($first->json('data.0.is_liked'));
        $this->assertStringContainsString('private', $first->headers->get('Cache-Control'));

        Sanctum::actingAs($secondViewer);
        $second = $this->getJson($this->endpoint($product))->assertOk();
        $this->assertFalse($second->json('data.0.is_liked'));
        $this->assertStringContainsString('no-store', $second->headers->get('Cache-Control'));
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
