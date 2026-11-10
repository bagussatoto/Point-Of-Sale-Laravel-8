<?php

namespace Tests\Feature;

use App\Models\Kategori;
use App\Models\Produk;
use App\Models\User;
use Database\Seeders\SettingTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterDataValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingTableSeeder::class);

        $user = User::factory()->create();
        $user->level = 1;
        $user->save();
        $this->admin = $user;
    }

    public function test_kategori_validation_rejects_empty_and_duplicate_names()
    {
        $this->actingAs($this->admin)
            ->postJson(route('kategori.store'), ['nama_kategori' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nama_kategori']);

        $this->actingAs($this->admin)
            ->postJson(route('kategori.store'), ['nama_kategori' => 'Minuman'])
            ->assertStatus(200);

        $this->actingAs($this->admin)
            ->postJson(route('kategori.store'), ['nama_kategori' => 'Minuman'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nama_kategori']);
    }

    public function test_kategori_in_use_by_produk_cannot_be_deleted()
    {
        $kategori = Kategori::create(['nama_kategori' => 'Makanan']);
        Produk::create([
            'id_kategori' => $kategori->id_kategori,
            'kode_produk' => 'P000001',
            'nama_produk' => 'Roti Tawar',
            'harga_beli' => 10000,
            'harga_jual' => 15000,
            'diskon' => 0,
            'stok' => 10,
        ]);

        $this->actingAs($this->admin)
            ->deleteJson(route('kategori.destroy', $kategori->id_kategori))
            ->assertStatus(422);

        $this->assertDatabaseHas('kategori', ['id_kategori' => $kategori->id_kategori]);
    }

    public function test_produk_validation_and_auto_kode_generation()
    {
        $kategori = Kategori::create(['nama_kategori' => 'Snack']);

        $this->actingAs($this->admin)
            ->postJson(route('produk.store'), [
                'id_kategori' => $kategori->id_kategori,
                'nama_produk' => '',
                'harga_beli' => -100,
                'harga_jual' => 5000,
                'stok' => 5,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['nama_produk', 'harga_beli']);

        $this->actingAs($this->admin)
            ->postJson(route('produk.store'), [
                'id_kategori' => $kategori->id_kategori,
                'nama_produk' => 'Keripik Kentang',
                'merk' => 'Chitato',
                'harga_beli' => 8000,
                'harga_jual' => 12000,
                'diskon' => 10,
                'stok' => 25,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('produk', [
            'kode_produk' => 'P000001',
            'nama_produk' => 'Keripik Kentang',
        ]);
    }

    public function test_bulk_actions_handle_empty_selection_without_crashing()
    {
        $this->actingAs($this->admin)
            ->post(route('produk.delete_selected'), [])
            ->assertStatus(204);

        $this->actingAs($this->admin)
            ->post(route('produk.cetak_barcode'), [])
            ->assertStatus(302);

        $this->actingAs($this->admin)
            ->post(route('member.cetak_member'), [])
            ->assertStatus(302);
    }

    public function test_admin_cannot_delete_own_account()
    {
        $this->actingAs($this->admin)
            ->deleteJson(route('user.destroy', $this->admin->id))
            ->assertStatus(400);

        $this->assertDatabaseHas('users', ['id' => $this->admin->id]);
    }
}
