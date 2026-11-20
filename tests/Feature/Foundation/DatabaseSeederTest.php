<?php

namespace Tests\Feature\Foundation;

use App\Enums\Role;
use App\Models\Cabang;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_pilot_branches_and_one_default_account_per_staff_role(): void
    {
        config(['auflow.seed.default_password' => 'rahasia-lokal-123']);

        $this->seed();

        $this->assertSame(2, Cabang::count());
        $cabangPertama = Cabang::query()->orderBy('id')->firstOrFail();

        $harapan = [
            'superadmin@auflow.test' => [Role::SuperAdmin, null],
            'admin.cabang@auflow.test' => [Role::AdminCabang, $cabangPertama->id],
            'kasir@auflow.test' => [Role::Kasir, $cabangPertama->id],
            'kurir@auflow.test' => [Role::Kurir, $cabangPertama->id],
        ];

        $this->assertSame(4, User::count());

        foreach ($harapan as $email => [$role, $cabangId]) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame($role, $user->role, $email);
            $this->assertSame($cabangId, $user->cabang_id, $email);
            $this->assertTrue($user->is_active, $email);
            $this->assertTrue(Hash::check('rahasia-lokal-123', $user->password), $email);
        }
    }

    public function test_seeded_staff_accounts_follow_web_access_rules(): void
    {
        config(['auflow.seed.default_password' => 'rahasia-lokal-123']);
        $this->seed();

        foreach (['superadmin@auflow.test', 'admin.cabang@auflow.test', 'kasir@auflow.test'] as $email) {
            $this->post(route('login.store'), ['email' => $email, 'password' => 'rahasia-lokal-123'])
                ->assertRedirect(route('dashboard', absolute: false));
            $this->post(route('logout'));
        }

        $this->post(route('login.store'), ['email' => 'kurir@auflow.test', 'password' => 'rahasia-lokal-123'])
            ->assertRedirect(route('akses-ditolak'));
    }

    public function test_it_generates_a_random_password_when_none_is_configured(): void
    {
        config(['auflow.seed.default_password' => null]);

        $this->seed();

        $user = User::where('email', 'superadmin@auflow.test')->firstOrFail();
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertFalse(Hash::check('', $user->password));
    }

    public function test_custom_super_admin_email_is_used_and_blank_falls_back_to_default(): void
    {
        config(['auflow.seed.superadmin_email' => 'pemilik@auflow.test']);
        $this->seed();
        $this->assertSame(Role::SuperAdmin, User::where('email', 'pemilik@auflow.test')->firstOrFail()->role);

        config(['auflow.seed.superadmin_email' => '']);
        $this->seed();
        $this->assertTrue(User::where('email', 'superadmin@auflow.test')->exists());
        $this->assertFalse(User::where('email', '')->exists());
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed();
        $this->seed();

        $this->assertSame(4, User::count());
        $this->assertSame(2, Cabang::count());
    }

    public function test_default_accounts_are_not_created_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, User::count());
        $this->assertSame(2, Cabang::count());
    }
}
