<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderDeletePermissionTest extends TestCase
{
    use DatabaseTransactions;

    private function userWithRole(string $slug): User
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug]);
        $user = User::factory()->create();
        DB::table('user_roles')->insert(['user_id' => $user->id, 'role_id' => $role->id]);

        return $user->fresh();
    }

    private function newOrder(): Order
    {
        return Order::factory()->create(['status' => OrderStatus::NEW]);
    }

    public function test_manager_cannot_delete_order(): void
    {
        $order = $this->newOrder();
        Sanctum::actingAs($this->userWithRole('manager'));

        $this->deleteJson("/api/orders/{$order->id}")->assertStatus(403);

        $this->assertNull($order->fresh()->deleted_at);
        $this->assertSame(OrderStatus::NEW, $order->fresh()->status);
    }

    public function test_super_admin_can_delete_order(): void
    {
        $order = $this->newOrder();
        Sanctum::actingAs($this->userWithRole('super-admin'));

        $this->deleteJson("/api/orders/{$order->id}")->assertOk();

        $this->assertNotNull(Order::withTrashed()->find($order->id)->deleted_at);
    }

    public function test_manager_with_explicit_permission_can_delete_order(): void
    {
        $order = $this->newOrder();
        $manager = $this->userWithRole('manager');
        $permission = Permission::firstOrCreate(
            ['slug' => 'orders.delete'],
            ['name' => 'Удаление заказов']
        );
        DB::table('user_permissions')->insert(['user_id' => $manager->id, 'permission_id' => $permission->id]);
        Sanctum::actingAs($manager->fresh());

        $this->deleteJson("/api/orders/{$order->id}")->assertOk();

        $this->assertNotNull(Order::withTrashed()->find($order->id)->deleted_at);
    }
}
