<?php

namespace Tests\Feature\Admin;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CategoryOrderingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_menu_and_home_banner_orders_are_saved_independently(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $first = Category::factory()->create([
            'name' => 'Первая',
            'show_in_catalog_menu' => true,
            'show_as_home_banner' => true,
            'menu_order' => 1,
            'home_banner_order' => 2,
        ]);
        $second = Category::factory()->create([
            'name' => 'Вторая',
            'show_in_catalog_menu' => true,
            'show_as_home_banner' => true,
            'menu_order' => 2,
            'home_banner_order' => 1,
        ]);

        $this->postJson('/api/categories/reorder', [
            'type' => 'menu',
            'groups' => [[
                'parent_id' => null,
                'category_ids' => [$second->id, $first->id],
            ]],
        ])->assertOk();

        $this->postJson('/api/categories/reorder', [
            'type' => 'home_banner',
            'groups' => [[
                'parent_id' => null,
                'category_ids' => [$first->id, $second->id],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('categories', [
            'id' => $first->id,
            'menu_order' => 2,
            'home_banner_order' => 1,
        ]);
        $this->assertDatabaseHas('categories', [
            'id' => $second->id,
            'menu_order' => 1,
            'home_banner_order' => 2,
        ]);
    }
}
