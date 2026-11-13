<?php

namespace Tests\Feature;

use App\Models\Slide;
use App\Models\User;
use App\Services\Slide\SlideOrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SlideOrderTest extends TestCase
{
    use DatabaseTransactions;

    /** Порядок активных слайдов по возрастанию: [id => order]. */
    private function activeOrders(): array
    {
        return Slide::query()->where('is_active', true)->orderBy('order')->pluck('order', 'id')->all();
    }

    private function slides(): array
    {
        Slide::query()->delete();

        return [
            'a' => Slide::create(['title' => 'A', 'order' => 0, 'is_active' => true]),
            'b' => Slide::create(['title' => 'B', 'order' => 1, 'is_active' => true]),
            'c' => Slide::create(['title' => 'C', 'order' => 4, 'is_active' => true]),
            'off' => Slide::create(['title' => 'Off', 'order' => 5, 'is_active' => false]),
        ];
    }

    public function test_moving_a_slide_up_shifts_the_others_down(): void
    {
        $s = $this->slides();

        app(SlideOrderService::class)->move($s['c'], 0);

        $this->assertSame([$s['c']->id => 0, $s['a']->id => 1, $s['b']->id => 2], $this->activeOrders());
    }

    public function test_moving_a_slide_down_shifts_the_others_up(): void
    {
        $s = $this->slides();

        app(SlideOrderService::class)->move($s['a'], 4);

        $this->assertSame([$s['b']->id => 0, $s['c']->id => 3, $s['a']->id => 4], $this->activeOrders());
    }

    public function test_same_order_changes_nothing(): void
    {
        $s = $this->slides();

        $result = app(SlideOrderService::class)->move($s['b'], 1);

        $this->assertSame('Порядок не изменился', $result['message']);
        $this->assertSame([$s['a']->id => 0, $s['b']->id => 1, $s['c']->id => 4], $this->activeOrders());
    }

    public function test_inactive_slide_does_not_move_the_others(): void
    {
        $s = $this->slides();

        app(SlideOrderService::class)->move($s['off'], 0);

        $this->assertSame(0, $s['off']->fresh()->order);
        $this->assertSame([$s['a']->id => 0, $s['b']->id => 1, $s['c']->id => 4], $this->activeOrders());
    }

    public function test_endpoint_moves_the_slide_and_validates_input(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $s = $this->slides();

        $this->postJson("/api/slides/{$s['c']->id}/order", ['order' => 0])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('new_order', 0);
        $this->assertSame([$s['c']->id => 0, $s['a']->id => 1, $s['b']->id => 2], $this->activeOrders());

        $this->postJson("/api/slides/{$s['c']->id}/order", ['order' => -1])->assertStatus(422);
        $this->postJson("/api/slides/{$s['c']->id}/order", [])->assertStatus(422);
    }
}
