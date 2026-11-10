<?php

namespace App\Services;

use App\Models\Pembelian;
use App\Models\Pengeluaran;
use App\Models\Penjualan;

class LaporanService
{
    /**
     * Mengambil ringkasan harian penjualan, pembelian, pengeluaran, dan pendapatan
     * dalam satu rentang tanggal menggunakan 3 query agregat (menghindari N+1 loop).
     *
     * @return array<int, array{tanggal: string, penjualan: int, pembelian: int, pengeluaran: int, pendapatan: int}>
     */
    public function getRingkasanHarian(string $awal, string $akhir): array
    {
        $start = $awal . ' 00:00:00';
        $end = $akhir . ' 23:59:59';

        $penjualanPerHari = Penjualan::selectRaw('DATE(created_at) as tanggal, SUM(bayar) as total')
            ->whereBetween('created_at', [$start, $end])
            ->groupByRaw('DATE(created_at)')
            ->pluck('total', 'tanggal');

        $pembelianPerHari = Pembelian::selectRaw('DATE(created_at) as tanggal, SUM(bayar) as total')
            ->whereBetween('created_at', [$start, $end])
            ->groupByRaw('DATE(created_at)')
            ->pluck('total', 'tanggal');

        $pengeluaranPerHari = Pengeluaran::selectRaw('DATE(created_at) as tanggal, SUM(nominal) as total')
            ->whereBetween('created_at', [$start, $end])
            ->groupByRaw('DATE(created_at)')
            ->pluck('total', 'tanggal');

        $ringkasan = [];
        $currentDate = $awal;

        while (strtotime($currentDate) <= strtotime($akhir)) {
            $penjualan = (int) ($penjualanPerHari[$currentDate] ?? 0);
            $pembelian = (int) ($pembelianPerHari[$currentDate] ?? 0);
            $pengeluaran = (int) ($pengeluaranPerHari[$currentDate] ?? 0);

            $ringkasan[] = [
                'tanggal' => $currentDate,
                'penjualan' => $penjualan,
                'pembelian' => $pembelian,
                'pengeluaran' => $pengeluaran,
                'pendapatan' => $penjualan - $pembelian - $pengeluaran,
            ];

            $currentDate = date('Y-m-d', strtotime('+1 day', strtotime($currentDate)));
        }

        return $ringkasan;
    }
}
