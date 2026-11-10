<?php

namespace App\Http\Controllers;

use App\Models\Kategori;
use App\Models\Member;
use App\Models\Produk;
use App\Models\Supplier;
use App\Services\LaporanService;

class DashboardController extends Controller
{
    public function index(LaporanService $laporanService)
    {
        if (auth()->user()->level != 1) {
            return view('kasir.dashboard');
        }

        $kategori = Kategori::count();
        $produk = Produk::count();
        $supplier = Supplier::count();
        $member = Member::count();

        $tanggal_awal = date('Y-m-01');
        $tanggal_akhir = date('Y-m-d');

        $ringkasan = $laporanService->getRingkasanHarian($tanggal_awal, $tanggal_akhir);
        $data_tanggal = [];
        $data_pendapatan = [];

        foreach ($ringkasan as $item) {
            $data_tanggal[] = (int) substr($item['tanggal'], 8, 2);
            $data_pendapatan[] = $item['pendapatan'];
        }

        return view('admin.dashboard', compact('kategori', 'produk', 'supplier', 'member', 'tanggal_awal', 'tanggal_akhir', 'data_tanggal', 'data_pendapatan'));
    }
}
