<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPasswordManagementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_super_admin_replaces_another_users_password(): void
    {
        $superAdmin = User::factory()->create();
        $role = Role::firstOrCreate(
            ['slug' => 'super-admin'],
            ['name' => 'Super admin'],
        );
        $superAdmin->roles()->attach($role);

        $user = User::factory()->create([
            'email' => 'managed-user@example.test',
            'password' => 'InitialPassword123!',
        ]);
        $oldToken = $user->createToken('old-session');
        Sanctum::actingAs($superAdmin);

        $this->putJson("/api/users/{$user->id}/update-password", [
            'password' => 'ReplacementPassword456!',
            'password_confirmation' => 'ReplacementPassword456!',
        ])->assertOk()->assertJsonPath('message', 'Пароль успешно обновлён.');

        $user->refresh();
        $this->assertTrue(Hash::check('ReplacementPassword456!', $user->password));
        $this->assertFalse(Hash::check('InitialPassword123!', $user->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $oldToken->accessToken->id]);
    }

    public function test_regular_user_cannot_replace_another_users_password(): void
    {
        $actor = User::factory()->create();
        $user = User::factory()->create(['password' => 'InitialPassword123!']);
        Sanctum::actingAs($actor);

        $this->putJson("/api/users/{$user->id}/update-password", [
            'password' => 'ReplacementPassword456!',
            'password_confirmation' => 'ReplacementPassword456!',
        ])->assertForbidden();

        $this->assertTrue(Hash::check('InitialPassword123!', $user->fresh()->password));
    }
}
