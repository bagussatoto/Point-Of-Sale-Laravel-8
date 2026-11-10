<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\SettingTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosAccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingTableSeeder::class);
    }

    private function createUserWithLevel(int $level): User
    {
        $user = User::factory()->create();
        $user->level = $level;
        $user->save();

        return $user;
    }

    public function test_admin_can_access_admin_routes_and_dashboard()
    {
        $admin = $this->createUserWithLevel(1);

        $this->actingAs($admin)->get(route('dashboard'))->assertStatus(200);
        $this->actingAs($admin)->get(route('produk.index'))->assertStatus(200);
        $this->actingAs($admin)->get(route('laporan.index'))->assertStatus(200);
        $this->actingAs($admin)->get(route('user.index'))->assertStatus(200);
    }

    public function test_kasir_can_access_dashboard_and_pos_transaction_routes()
    {
        $kasir = $this->createUserWithLevel(2);

        $this->actingAs($kasir)->get(route('dashboard'))->assertStatus(200);
        $this->actingAs($kasir)
            ->get(route('transaksi.baru'))
            ->assertRedirect(route('transaksi.index'));
    }

    public function test_kasir_is_redirected_from_admin_only_routes()
    {
        $kasir = $this->createUserWithLevel(2);

        $this->actingAs($kasir)->get(route('produk.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($kasir)->get(route('laporan.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($kasir)->get(route('user.index'))->assertRedirect(route('dashboard'));
        $this->actingAs($kasir)->get(route('setting.index'))->assertRedirect(route('dashboard'));
    }

    public function test_kasir_accessing_active_transaction_without_session_redirects_to_new_transaction()
    {
        $kasir = $this->createUserWithLevel(2);

        $this->actingAs($kasir)
            ->get(route('transaksi.index'))
            ->assertRedirect(route('transaksi.baru'));
    }
}
