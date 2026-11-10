# Ringkasan Perbaikan & Audit Sistem (Changelog & Technical Report)

Dokumen ini mencatat seluruh temuan audit, analisis bug/error kritis, celah keamanan, inkonsistensi transaksi, serta detail perbaikan kode yang telah diterapkan pada aplikasi **Point of Sale (POS) Laravel 8**.

---

## 📌 1. Ikhtisar Status Perbaikan

| Kategori | Jumlah Isu | Status | Dampak Sebelum Perbaikan |
| :--- | :---: | :---: | :--- |
| **Fatal Errors (Crash)** | 5 | ✅ Terselesaikan | Dashboard blank di PHP 8+, kasir crash saat redirect, nota besar DomPDF gagal render. |
| **HTML / UI Rendering** | 4 | ✅ Terselesaikan | Tag penutup HTML unclosed/malformed di DataTables (`</spa>`, `</span`, `<span>`). |
| **Integritas Database** | 3 | ✅ Terselesaikan | Stok fisik tidak konsisten jika koneksi putus saat transaksi, duplikasi SKU saat data dihapus. |
| **Keamanan (Security)** | 2 | ✅ Terselesaikan | Upload file logo/kartu member tanpa filter tipe/ukuran file (risiko Remote Code Execution). |
| **PHP 8.1+ Compatibility** | 2 | ✅ Terselesaikan | Warning float conversion pada fungsi helper terbilang dan konversi objek Carbon ke string. |

---

## 🔍 2. Rincian Masalah Kritis & Solusi yang Diterapkan

### 2.1. Fatal Error Operator Array di Dashboard (PHP 8+)
- **File:** `app/Http/Controllers/DashboardController.php` (baris 37)
- **Masalah:** Penggunaan operator append-plus `$data_pendapatan[] += $pendapatan;`.
- **Dampak:** Di PHP 8.0+, sintaks ini melempar fatal error: `Fatal error: Cannot use [] for reading`. Akibatnya halaman Dashboard admin dan kasir tidak bisa dibuka sama sekali.
- **Solusi:**
  ```php
  // SEBELUM:
  $data_pendapatan[] += $pendapatan;

  // SESUDAH:
  $data_pendapatan[] = $pendapatan;
  ```
  Selain itu, variabel `$tanggal_awal` dipisahkan dengan `$current_date` saat perulangan agar tanggal awal tidak bergeser melewati tanggal akhir saat dikirim ke Blade view.

---

### 2.2. Missing Route `home` (Crash Redirect Kasir)
- **File:** `app/Http/Controllers/PenjualanDetailController.php` (baris 30)
- **Masalah:** Route `home` tidak terdaftar pada `routes/web.php` (route dashboard terdaftar dengan nama `dashboard`).
- **Dampak:** Ketika kasir mengklik menu transaksi aktif namun sesi transaksi belum terbentuk, aplikasi melempar: `RouteNotFoundException: Route [home] not defined`.
- **Solusi:**
  ```php
  // SEBELUM:
  } else {
      if (auth()->user()->level == 1) {
          return redirect()->route('transaksi.baru');
      } else {
          return redirect()->route('home'); // Crash
      }
  }

  // SESUDAH:
  } else {
      return redirect()->route('transaksi.baru');
  }
  ```

---

### 2.3. Parameter dan Typo Orientasi DomPDF
- **File Terdampak:**
  - `PenjualanController.php` (baris 178)
  - `MemberController.php` (baris 145)
  - `ProdukController.php` (baris 162)
  - `LaporanController.php` (baris 80)
- **Masalah:**
  1. Typo kata `'potrait'` (kurang huruf `r`). DomPDF hanya mengenali `'portrait'` atau `'landscape'`.
  2. Pada `PenjualanController::notaBesar`, fungsi `setPaper(0,0,609,440, 'potrait')` mengirim 5 argumen terpisah, padahal method `setPaper` DomPDF menerima signature `setPaper($paper, $orientation)`.
- **Dampak:** Render dokumen PDF invoice besar rusak atau ukuran halaman tidak sesuai.
- **Solusi:**
  ```php
  // SEBELUM:
  $pdf->setPaper(0,0,609,440, 'potrait');

  // SESUDAH:
  $pdf->setPaper([0, 0, 609, 440], 'portrait');
  ```

---

### 2.4. Crash `TypeError` saat Aksi Batch Checkbox Kosong
- **File Terdampak:**
  - `ProdukController.php` (`deleteSelected` & `cetakBarcode`)
  - `MemberController.php` (`cetakMember`)
- **Masalah:** Perulangan langsung mengeksekusi `foreach ($request->id_produk as $id)`. Jika user mengklik tombol tanpa mencentang data apa pun di DataTables, `$request->id_produk` bernilai `null`.
- **Dampak:** Di PHP 8+, perulangan terhadap `null` langsung melempar fatal error `TypeError: foreach() argument must be of type array|object, null given`.
- **Solusi:**
  ```php
  $id_produks = $request->id_produk ?? [];
  if (empty($id_produks)) {
      return redirect()->back();
  }
  foreach ($id_produks as $id) {
      $produk = Produk::find($id);
      if ($produk) {
          $produk->delete();
      }
  }
  ```

---

### 2.5. Integritas Transaksi Database (ACID Database Transactions)
- **File Terdampak:**
  - `PenjualanController.php` (`store` & `destroy`)
  - `PembelianController.php` (`store` & `destroy`)
- **Masalah:** Pemotongan atau penambahan stok produk dilakukan dalam perulangan biasa tanpa proteksi `DB::beginTransaction()` / `DB::commit()`.
- **Dampak:** Jika terjadi kegagalan sistem di tengah perulangan (misal: server timeout, database lock), data penjualan tercatat tetapi stok barang fisik tidak sinkron / selisih opname.
- **Solusi:** Seluruh proses dibungkus di dalam closure `DB::transaction()`:
  ```php
  DB::transaction(function () use ($penjualan, $request) {
      $penjualan->update();

      $detail = PenjualanDetail::where('id_penjualan', $penjualan->id_penjualan)->get();
      foreach ($detail as $item) {
          $item->diskon = $request->diskon;
          $item->update();

          $produk = Produk::find($item->id_produk);
          if ($produk) {
              $produk->stok -= $item->jumlah;
              $produk->update();
          }
      }
  });
  ```

---

### 2.6. Keamanan Unggah File (Cegah Remote Code Execution / RCE)
- **File Terdampak:**
  - `SettingController.php` (`update`)
  - `UserController.php` (`updateProfil`)
- **Masalah:** File upload dipindahkan langsung ke direktori publik menggunakan ekstensi bawaan client (`$file->getClientOriginalExtension()`) tanpa validasi tipe MIME atau batasan ukuran file.
- **Dampak:** Potensi penyerang mengunggah skrip web shell berbahaya (seperti file `.php`) dan mengeksekusinya secara langsung via browser.
- **Solusi:** Menambahkan validasi Laravel sebelum proses pemindahan file:
  ```php
  $request->validate([
      'path_logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
      'path_kartu_member' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
      'foto' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
  ]);
  ```

---

### 2.7. Proteksi Penghapusan Akun Sendiri
- **File:** `app/Http/Controllers/UserController.php` (baris 113)
- **Masalah:** Tidak ada pengecekan apakah ID user yang akan dihapus sama dengan ID user yang sedang login (`auth()->id()`).
- **Dampak:** Administrator dapat secara tidak sengaja menghapus akunnya sendiri yang sedang aktif digunakan.
- **Solusi:**
  ```php
  public function destroy($id)
  {
      if ($id == auth()->id()) {
          return response()->json('Tidak dapat menghapus akun sendiri', 400);
      }

      $user = User::find($id);
      if ($user) {
          $user->delete();
      }

      return response(null, 204);
  }
  ```

---

### 2.8. Penanganan Produk Terhapus pada Riwayat Transaksi (Null-Safety)
- **File Terdampak:**
  - `PenjualanController.php` (`show`)
  - `PembelianController.php` (`show`)
- **Masalah:** Menampilkan `$detail->produk->kode_produk` secara langsung. Jika master produk terhapus dari sistem, objek `produk` bernilai `null`.
- **Dampak:** Membuka detail transaksi lama akan memicu exception: `Attempt to read property "kode_produk" on null`.
- **Solusi:**
  ```php
  ->addColumn('kode_produk', function ($detail) {
      return '<span class="label label-success">'. ($detail->produk->kode_produk ?? '-') .'</span>';
  })
  ->addColumn('nama_produk', function ($detail) {
      return $detail->produk->nama_produk ?? '-';
  })
  ```

---

### 2.9. Typo HTML Tag pada Render DataTables
- **File Terdampak:**
  - `PenjualanController.php`: Typo `</spa>` diubah menjadi `</span>`.
  - `PenjualanDetailController.php`: Tag `</span` kurang tanda `>` diubah menjadi `</span>`.
  - `PembelianDetailController.php`: Tag `</span` kurang tanda `>` diubah menjadi `</span>`.
  - `MemberController.php`: Tag penutup yang keliru ditulis pembuka `<span>` diubah menjadi `</span>`.

---

### 2.10. Kompatibilitas Helper di PHP 8.1+
- **File:** `app/Http/Helpers/helpers.php`
- **Masalah:**
  1. Operasi pembagian integer seperti `$angka / 10` menghasilkan float. Di PHP 8.1+, passing float ke pemanggilan array memunculkan *deprecation warning*.
  2. Belum ada dukungan bilangan besar (milyar).
  3. Konversi objek tanggal `Carbon` ke string pada `tanggal_indonesia()`.
- **Solusi:** Menambahkan casting integer eksplisit `abs((int) $angka)`, dukungan hingga 1 triliun (`milyar`), serta casting string aman `$tgl = (string) $tgl;`.

---

## 📁 3. Daftar File yang Diperbarui

| Nama File | Jenis Perubahan |
| :--- | :--- |
| `app/Http/Controllers/DashboardController.php` | Perbaikan fatal error PHP 8+ operator `[] +=` dan penanganan tanggal. |
| `app/Http/Controllers/PenjualanDetailController.php` | Perbaikan route kasir `home` ➔ `transaksi.baru` & perbaikan tag HTML `</span>`. |
| `app/Http/Controllers/PembelianDetailController.php` | Perbaikan tag HTML `</span>` & penambahan null-safety relasi produk. |
| `app/Http/Controllers/PenjualanController.php` | Transaksi database ACID, perbaikan parameter & typo DomPDF, eager loading relasi kasir. |
| `app/Http/Controllers/PembelianController.php` | Transaksi database ACID, eager loading relasi supplier, null-safety histori pembelian. |
| `app/Http/Controllers/MemberController.php` | Null-check cetak member, perbaikan typo HTML, perbaikan orientasi PDF. |
| `app/Http/Controllers/ProdukController.php` | Null-check multi-delete & barcode, perbaikan orientasi PDF, perbaikan urutan kode produk. |
| `app/Http/Controllers/LaporanController.php` | Koreksi orientasi DomPDF `'portrait'`. |
| `app/Http/Controllers/SettingController.php` | Validasi upload file gambar logo & kartu member, null-safe `Setting::first()`. |
| `app/Http/Controllers/UserController.php` | Proteksi hapus akun sendiri & validasi upload foto profil. |
| `app/Http/Helpers/helpers.php` | Kompatibilitas PHP 8.1+ fungsi `terbilang()` dan `tanggal_indonesia()`. |
| `README.md` | Dokumentasi arsitektur sistem, ERD interaktif Mermaid, rotasi database, dan SCM flow. |
| `.env` | File konfigurasi environment disalin dari `.env.example`. |

---

## 🚀 4. Riwayat Commit Git

Seluruh perbaikan telah diunggah ke repositori GitHub:
- **Repository URL:** [https://github.com/bagussatoto/Point-Of-Sale-Laravel-8](https://github.com/bagussatoto/Point-Of-Sale-Laravel-8)
- **Commit 1:** `Fix critical errors, DomPDF issues, database transactions, and security validations` (`a15ff8c`)
- **Commit 2:** `docs: revamp README with professional ERD, Database Workflow lifecycle, and SCM architecture` (`e0692cf`)

---

## ✅ 5. Rekomendasi Pemeliharaan Mendatang
1. **Peningkatan Versi Laravel:** Mengingat Laravel 8 telah mencapai batas akhir *End of Life* (EOL), disarankan merencanakan peningkatan bertahap ke Laravel 10 / 11 untuk mendapatkan pembaruan keamanan jangka panjang.
2. **Implementasi Soft Deletes:** Mengaktifkan trait `SoftDeletes` pada model `Produk`, `Member`, dan `Supplier` agar histori transaksi masa lalu tetap memiliki referensi data utuh meskipun item telah dinonaktifkan dari etalase.
3. **Queue Worker untuk PDF & Laporan:** Untuk transaksi dengan ribuan item, pembuatan file PDF dan cetak massal dapat didelegasikan ke Laravel Queue (`database` / `redis`) agar respons browser kasir tetap instan.
