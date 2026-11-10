<?php

namespace Tests\Feature;

use App\Models\Kategori;
use App\Models\Pembelian;
use App\Models\PembelianDetail;
use App\Models\Pengeluaran;
use App\Models\Penjualan;
use App\Models\Produk;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LaporanService;
use Database\Seeders\SettingTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PembelianAndLaporanTest extends TestCase
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

    public function test_pembelian_store_increases_stock_and_destroy_reverts_stock()
    {
        $kategori = Kategori::create(['nama_kategori' => 'ATK']);
        $produk = Produk::create([
            'id_kategori' => $kategori->id_kategori,
            'kode_produk' => 'P000001',
            'nama_produk' => 'Pulpen Hitam',
            'harga_beli' => 2000,
            'harga_jual' => 3500,
            'diskon' => 0,
            'stok' => 5,
        ]);

        $supplier = Supplier::create([
            'nama' => 'PT Sumber Makmur',
            'telepon' => '08111111111',
            'alamat' => 'Bandung',
        ]);

        $this->actingAs($this->admin)
            ->get(route('pembelian.create', $supplier->id_supplier))
            ->assertRedirect(route('pembelian_detail.index'));

        $pembelian = Pembelian::latest('id_pembelian')->first();
        $this->assertNotNull($pembelian);

        $this->actingAs($this->admin)
            ->postJson(route('pembelian_detail.store'), [
                'id_pembelian' => $pembelian->id_pembelian,
                'id_produk' => $produk->id_produk,
            ])
            ->assertStatus(200);

        $detail = PembelianDetail::where('id_pembelian', $pembelian->id_pembelian)->first();

        $this->actingAs($this->admin)
            ->putJson(route('pembelian_detail.update', $detail->id_pembelian_detail), [
                'jumlah' => 15,
            ])
            ->assertStatus(200);

        $this->actingAs($this->admin)
            ->post(route('pembelian.store'), [
                'id_pembelian' => $pembelian->id_pembelian,
                'total_item' => 15,
                'total' => 30000,
                'diskon' => 0,
                'bayar' => 30000,
            ])
            ->assertRedirect(route('pembelian.index'));

        $this->assertEquals(20, $produk->fresh()->stok);

        $this->actingAs($this->admin)
            ->delete(route('pembelian.destroy', $pembelian->id_pembelian))
            ->assertStatus(204);

        $this->assertEquals(5, $produk->fresh()->stok);
    }

    public function test_laporan_service_computes_daily_net_income_accurately()
    {
        $today = date('Y-m-d');

        Penjualan::create([
            'id_user' => $this->admin->id,
            'total_item' => 2,
            'total_harga' => 100000,
            'diskon' => 0,
            'bayar' => 100000,
            'diterima' => 100000,
        ]);

        $supplier = Supplier::create([
            'nama' => 'Supplier A',
            'telepon' => '0812000000',
            'alamat' => 'Surabaya',
        ]);

        Pembelian::create([
            'id_supplier' => $supplier->id_supplier,
            'total_item' => 5,
            'total_harga' => 40000,
            'diskon' => 0,
            'bayar' => 40000,
        ]);

        Pengeluaran::create([
            'deskripsi' => 'Listrik Toko',
            'nominal' => 15000,
        ]);

        $service = app(LaporanService::class);
        $ringkasan = $service->getRingkasanHarian($today, $today);

        $this->assertCount(1, $ringkasan);
        $this->assertEquals(100000, $ringkasan[0]['penjualan']);
        $this->assertEquals(40000, $ringkasan[0]['pembelian']);
        $this->assertEquals(15000, $ringkasan[0]['pengeluaran']);
        $this->assertEquals(45000, $ringkasan[0]['pendapatan']);
    }
}
