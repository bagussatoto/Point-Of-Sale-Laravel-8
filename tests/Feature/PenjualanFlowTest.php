<?php

namespace Tests\Feature;

use App\Models\Kategori;
use App\Models\Member;
use App\Models\Penjualan;
use App\Models\Produk;
use App\Models\User;
use Database\Seeders\SettingTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PenjualanFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SettingTableSeeder::class);

        $user = User::factory()->create();
        $user->level = 2;
        $user->save();
        $this->kasir = $user;
    }

    public function test_penjualan_store_deducts_stock_and_destroy_restores_stock()
    {
        $admin = User::factory()->create();
        $admin->level = 1;
        $admin->save();

        $kategori = Kategori::create(['nama_kategori' => 'Elektronik']);
        $produk = Produk::create([
            'id_kategori' => $kategori->id_kategori,
            'kode_produk' => 'P000001',
            'nama_produk' => 'Mouse Wireless',
            'harga_beli' => 50000,
            'harga_jual' => 80000,
            'diskon' => 0,
            'stok' => 20,
        ]);

        $member = Member::create([
            'kode_member' => '00001',
            'nama' => 'Budi',
            'telepon' => '08123456789',
            'alamat' => 'Jakarta',
        ]);

        // 1. Kasir memulai transaksi baru
        $this->actingAs($this->kasir)
            ->get(route('transaksi.baru'))
            ->assertRedirect(route('transaksi.index'));

        $penjualan = Penjualan::latest('id_penjualan')->first();
        $this->assertNotNull($penjualan);

        // 2. Menambahkan produk ke keranjang transaksi
        $this->actingAs($this->kasir)
            ->postJson(route('transaksi.store'), [
                'id_penjualan' => $penjualan->id_penjualan,
                'id_produk' => $produk->id_produk,
            ])
            ->assertStatus(200);

        $detail = \App\Models\PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->first();
        $this->assertNotNull($detail);

        // 3. Mengubah jumlah item menjadi 3
        $this->actingAs($this->kasir)
            ->putJson(route('transaksi.update', $detail->id_penjualan_detail), [
                'jumlah' => 3,
            ])
            ->assertStatus(200);

        // 4. Menyimpan transaksi penjualan (diskon 5% dari 240.000 = 228.000)
        $this->actingAs($this->kasir)
            ->post(route('transaksi.simpan'), [
                'id_penjualan' => $penjualan->id_penjualan,
                'id_member' => $member->id_member,
                'total_item' => 3,
                'total' => 240000,
                'diskon' => 5,
                'bayar' => 228000,
                'diterima' => 250000,
            ])
            ->assertRedirect(route('transaksi.selesai'));

        $this->assertEquals(17, $produk->fresh()->stok);

        // 5. Hapus transaksi oleh admin harus mengembalikan stok menjadi 20
        $this->actingAs($admin)
            ->delete(route('penjualan.destroy', $penjualan->id_penjualan))
            ->assertStatus(204);

        $this->assertEquals(20, $produk->fresh()->stok);
        $this->assertDatabaseMissing('penjualan', ['id_penjualan' => $penjualan->id_penjualan]);
    }
}
