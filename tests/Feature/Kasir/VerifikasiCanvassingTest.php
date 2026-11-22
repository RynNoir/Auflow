<?php

namespace Tests\Feature\Kasir;

use App\Enums\MetodePembayaran;
use App\Enums\StatusTransaksi;
use App\Models\Cabang;
use App\Models\Pelanggan;
use App\Models\RiwayatStatus;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\VerifikasiCanvassingService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class VerifikasiCanvassingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00:00'));
        config([
            'auflow.harga.walk_in' => 6000,
            'auflow.harga.galon_baru' => 45000,
            'auflow.harga.cod' => 7000,
        ]);
    }

    // ─── 1. Akses Halaman & Otorisasi ──────────────────────────────────────────

    public function test_kasir_aktif_dapat_mengakses_halaman_verifikasi_canvassing(): void
    {
        $cabang = Cabang::factory()->create(['nama' => 'Cabang Dadok Tunggul Hitam']);
        $kasir = User::factory()->kasir()->for($cabang)->create(['name' => 'Kasir Shalwa']);

        $this->actingAs($kasir)
            ->get(route('kasir.canvassing'))
            ->assertOk()
            ->assertSee('Verifikasi Catatan Canvassing')
            ->assertSee('Cabang Dadok Tunggul Hitam')
            ->assertSeeHtml('data-test="stat-menunggu"')
            ->assertSeeHtml('data-test="stat-galon"')
            ->assertSeeHtml('data-test="stat-setoran"');
    }

    public function test_menu_verifikasi_canvassing_tampil_di_sidebar_untuk_kasir(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();
        $adminCabang = User::factory()->adminCabang()->for($cabang)->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($kasir)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('kasir.canvassing'))
            ->assertSee('Verifikasi Canvassing');

        $this->actingAs($adminCabang)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('kasir.canvassing'));

        $this->actingAs($superAdmin)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('kasir.canvassing'));
    }

    public function test_admin_cabang_dan_super_admin_ditolak_dari_halaman_verifikasi_kasir(): void
    {
        $cabang = Cabang::factory()->create();
        $adminCabang = User::factory()->adminCabang()->for($cabang)->create();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($adminCabang)
            ->get(route('kasir.canvassing'))
            ->assertForbidden();

        $this->actingAs($superAdmin)
            ->get(route('kasir.canvassing'))
            ->assertForbidden();
    }

    public function test_kurir_ditolak_dari_halaman_verifikasi_kasir(): void
    {
        $cabang = Cabang::factory()->create();
        $kurir = User::factory()->kurir()->for($cabang)->create();

        $this->actingAs($kurir)
            ->get(route('kasir.canvassing'))
            ->assertRedirect(route('akses-ditolak'));
    }

    public function test_pelanggan_ditolak_dari_halaman_verifikasi_kasir(): void
    {
        $pelanggan = Pelanggan::factory()->create();

        $this->actingAs($pelanggan, 'pelanggan')
            ->get(route('kasir.canvassing'))
            ->assertForbidden();
    }

    public function test_tamu_diarahkan_ke_halaman_login(): void
    {
        $this->get(route('kasir.canvassing'))
            ->assertRedirect(route('login'));
    }

    public function test_kasir_nonaktif_ditolak_dari_halaman_verifikasi_kasir(): void
    {
        $cabang = Cabang::factory()->create();
        $kasirNonaktif = User::factory()->kasir()->for($cabang)->create(['is_active' => false]);

        $this->actingAs($kasirNonaktif)
            ->get(route('kasir.canvassing'))
            ->assertRedirect(route('login'));
    }

    // ─── 2. Tampilan Data & Isolasi Cabang ──────────────────────────────────────

    public function test_halaman_menampilkan_catatan_canvassing_menunggu_verifikasi_di_cabangnya(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();
        $kurir = User::factory()->kurir()->for($cabang)->create(['name' => 'Budi Kurir']);

        $canvassing = Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
            'jumlah_galon' => 5,
            'total_harga' => 35000,
            'metode_bayar' => MetodePembayaran::Tunai,
        ]);

        $this->actingAs($kasir);

        Livewire::test('pages::kasir.canvassing')
            ->assertSee('#CV-'.$canvassing->id)
            ->assertSee('Budi Kurir')
            ->assertSee('5 Galon')
            ->assertSee('Rp35.000')
            ->assertSee('Tunai')
            ->assertSee('Menunggu verifikasi')
            ->assertSeeHtml('data-test="tombol-terima-'.$canvassing->id.'"')
            ->assertSeeHtml('data-test="tombol-tolak-'.$canvassing->id.'"');
    }

    public function test_canvassing_tidak_menampilkan_data_pelanggan(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();
        $kurir = User::factory()->kurir()->for($cabang)->create(['name' => 'Kurir A']);

        Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
            'pelanggan_id' => null,
            'nama_pemesan' => null,
            'no_hp_pemesan' => null,
            'alamat_antar' => null,
        ]);

        $this->actingAs($kasir);

        Livewire::test('pages::kasir.canvassing')
            ->assertDontSee('Nama Pelanggan')
            ->assertDontSee('Alamat Antar')
            ->assertDontSee('No. HP Pemesan');
    }

    public function test_halaman_menampilkan_ringkasan_stat_catatan_menunggu(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();
        $kurir = User::factory()->kurir()->for($cabang)->create();

        Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
            'jumlah_galon' => 10,
            'total_harga' => 70000,
        ]);

        Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
            'jumlah_galon' => 5,
            'total_harga' => 35000,
        ]);

        // Catatan yang sudah tervalidasi tidak ikut dihitung di stat card menunggu
        Transaksi::factory()->canvassing(StatusTransaksi::Tervalidasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
            'jumlah_galon' => 20,
            'total_harga' => 140000,
        ]);

        $this->actingAs($kasir);

        Livewire::test('pages::kasir.canvassing')
            ->assertSeeHtml('data-test="stat-menunggu"')
            ->assertSee('15') // 10 + 5 galon
            ->assertSee('Rp105.000'); // 70.000 + 35.000
    }

    public function test_kasir_tidak_bisa_melihat_atau_memverifikasi_canvassing_cabang_lain(): void
    {
        $cabangA = Cabang::factory()->create(['nama' => 'Cabang A']);
        $cabangB = Cabang::factory()->create(['nama' => 'Cabang B']);

        $kasirA = User::factory()->kasir()->for($cabangA)->create();
        $kurirB = User::factory()->kurir()->for($cabangB)->create();

        $canvassingB = Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabangB->id,
            'kurir_id' => $kurirB->id,
            'jumlah_galon' => 7,
            'total_harga' => 49000,
        ]);

        $this->actingAs($kasirA);

        // Kasir A tidak melihat catatan milik Cabang B
        Livewire::test('pages::kasir.canvassing')
            ->assertDontSee('#CV-'.$canvassingB->id)
            ->assertDontSee('Rp49.000');

        // Kasir A mencoba membuka modal terima transaksi Cabang B -> ditolak 404
        Livewire::test('pages::kasir.canvassing')
            ->call('bukaTerima', $canvassingB->id)
            ->assertNotFound();

        // Kasir A mencoba membuka modal tolak transaksi Cabang B -> ditolak 404
        Livewire::test('pages::kasir.canvassing')
            ->call('bukaTolak', $canvassingB->id)
            ->assertNotFound();
    }

    // ─── 3. Aksi Terima (Tervalidasi) ──────────────────────────────────────────

    public function test_kasir_dapat_menerima_catatan_canvassing_menjadi_tervalidasi(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create(['name' => 'Kasir Rani']);
        $kurir = User::factory()->kurir()->for($cabang)->create(['name' => 'Kurir Anton']);

        $canvassing = Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
            'jumlah_galon' => 4,
            'total_harga' => 28000,
            'metode_bayar' => MetodePembayaran::Tunai,
        ]);

        $this->actingAs($kasir);

        Livewire::test('pages::kasir.canvassing')
            ->call('bukaTerima', $canvassing->id)
            ->assertSet('modalTerima', true)
            ->assertSet('catatanTerimaId', $canvassing->id)
            ->set('catatanTerima', 'Uang tunai Rp28.000 diterima pas')
            ->call('simpanTerima')
            ->assertSet('modalTerima', false);

        $canvassing->refresh();

        $this->assertSame(StatusTransaksi::Tervalidasi, $canvassing->status);
        $this->assertSame($kasir->id, $canvassing->diverifikasi_oleh);

        // Tercatat di tabel riwayat_status
        $this->assertDatabaseHas('riwayat_status', [
            'transaksi_id' => $canvassing->id,
            'status_lama' => StatusTransaksi::MenungguVerifikasi->value,
            'status_baru' => StatusTransaksi::Tervalidasi->value,
            'diubah_oleh_staff' => $kasir->id,
            'catatan' => 'Uang tunai Rp28.000 diterima pas',
        ]);
    }

    public function test_menerima_canvassing_tanpa_catatan_tambahan_berhasil(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();
        $kurir = User::factory()->kurir()->for($cabang)->create();

        $canvassing = Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
            'jumlah_galon' => 3,
            'total_harga' => 21000,
            'metode_bayar' => MetodePembayaran::Qr,
        ]);

        $this->actingAs($kasir);

        Livewire::test('pages::kasir.canvassing')
            ->call('bukaTerima', $canvassing->id)
            ->call('simpanTerima');

        $canvassing->refresh();

        $this->assertSame(StatusTransaksi::Tervalidasi, $canvassing->status);
        $this->assertSame($kasir->id, $canvassing->diverifikasi_oleh);

        $riwayat = RiwayatStatus::where('transaksi_id', $canvassing->id)->firstOrFail();
        $this->assertSame(StatusTransaksi::Tervalidasi, $riwayat->status_baru);
        $this->assertNull($riwayat->catatan);
    }

    // ─── 4. Aksi Tolak (Ditolak dengan Alasan) ──────────────────────────────────

    public function test_kasir_dapat_menolak_catatan_canvassing_dengan_alasan_valid(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create(['name' => 'Kasir Doni']);
        $kurir = User::factory()->kurir()->for($cabang)->create(['name' => 'Kurir Eko']);

        $canvassing = Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
            'jumlah_galon' => 10,
            'total_harga' => 70000,
        ]);

        $this->actingAs($kasir);

        Livewire::test('pages::kasir.canvassing')
            ->call('bukaTolak', $canvassing->id)
            ->assertSet('modalTolak', true)
            ->assertSet('catatanTolakId', $canvassing->id)
            ->set('alasanTolak', 'Uang fisik kurang Rp14.000, kurir perlu setor ulang')
            ->call('simpanTolak')
            ->assertSet('modalTolak', false);

        $canvassing->refresh();

        $this->assertSame(StatusTransaksi::Ditolak, $canvassing->status);
        $this->assertSame($kasir->id, $canvassing->diverifikasi_oleh);

        // Tercatat di tabel riwayat_status dengan alasan penolakan
        $this->assertDatabaseHas('riwayat_status', [
            'transaksi_id' => $canvassing->id,
            'status_lama' => StatusTransaksi::MenungguVerifikasi->value,
            'status_baru' => StatusTransaksi::Ditolak->value,
            'diubah_oleh_staff' => $kasir->id,
            'catatan' => 'Uang fisik kurang Rp14.000, kurir perlu setor ulang',
        ]);
    }

    public function test_alasan_penolakan_wajib_diisi(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();
        $kurir = User::factory()->kurir()->for($cabang)->create();

        $canvassing = Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
        ]);

        $this->actingAs($kasir);

        Livewire::test('pages::kasir.canvassing')
            ->call('bukaTolak', $canvassing->id)
            ->set('alasanTolak', '')
            ->call('simpanTolak')
            ->assertHasErrors(['alasanTolak' => 'required'])
            ->assertSet('modalTolak', true);

        $canvassing->refresh();
        $this->assertSame(StatusTransaksi::MenungguVerifikasi, $canvassing->status);
    }

    public function test_alasan_penolakan_minimal_5_karakter(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();
        $kurir = User::factory()->kurir()->for($cabang)->create();

        $canvassing = Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
        ]);

        $this->actingAs($kasir);

        Livewire::test('pages::kasir.canvassing')
            ->call('bukaTolak', $canvassing->id)
            ->set('alasanTolak', 'Kur') // hanya 3 karakter
            ->call('simpanTolak')
            ->assertHasErrors(['alasanTolak' => 'min'])
            ->assertSet('modalTolak', true);

        $canvassing->refresh();
        $this->assertSame(StatusTransaksi::MenungguVerifikasi, $canvassing->status);
    }

    public function test_alasan_penolakan_maksimal_500_karakter(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();
        $kurir = User::factory()->kurir()->for($cabang)->create();

        $canvassing = Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
        ]);

        $this->actingAs($kasir);

        Livewire::test('pages::kasir.canvassing')
            ->call('bukaTolak', $canvassing->id)
            ->set('alasanTolak', str_repeat('a', 501))
            ->call('simpanTolak')
            ->assertHasErrors(['alasanTolak' => 'max'])
            ->assertSet('modalTolak', true);

        $canvassing->refresh();
        $this->assertSame(StatusTransaksi::MenungguVerifikasi, $canvassing->status);
    }

    // ─── 5. Pencegahan Duplikasi & Validasi Service ───────────────────────────

    public function test_service_menolak_transaksi_yang_bukan_kanal_canvassing(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();

        $transaksiCod = Transaksi::factory()->cod(StatusTransaksi::Diproses)->create([
            'cabang_id' => $cabang->id,
        ]);

        $service = app(VerifikasiCanvassingService::class);

        $this->expectException(ValidationException::class);
        $service->terima($transaksiCod, $kasir);
    }

    public function test_service_menolak_verifikasi_ulang_pada_catatan_yang_sudah_tervalidasi(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();

        $canvassing = Transaksi::factory()->canvassing(StatusTransaksi::Tervalidasi)->create([
            'cabang_id' => $cabang->id,
            'diverifikasi_oleh' => $kasir->id,
        ]);

        $service = app(VerifikasiCanvassingService::class);

        $this->expectException(ValidationException::class);
        $service->terima($canvassing, $kasir);
    }

    public function test_service_menolak_penolakan_pada_catatan_yang_sudah_ditolak(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();

        $canvassing = Transaksi::factory()->canvassing(StatusTransaksi::Ditolak)->create([
            'cabang_id' => $cabang->id,
            'diverifikasi_oleh' => $kasir->id,
        ]);

        $service = app(VerifikasiCanvassingService::class);

        $this->expectException(ValidationException::class);
        $service->tolak($canvassing, $kasir, 'Alasan ditolak ulang');
    }

    public function test_service_menolak_staf_yang_bukan_kasir(): void
    {
        $cabang = Cabang::factory()->create();
        $adminCabang = User::factory()->adminCabang()->for($cabang)->create();

        $canvassing = Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabang->id,
        ]);

        $service = app(VerifikasiCanvassingService::class);

        $this->expectException(AuthorizationException::class);
        $service->terima($canvassing, $adminCabang);
    }

    public function test_service_menolak_kasir_dari_cabang_berbeda(): void
    {
        $cabangA = Cabang::factory()->create();
        $cabangB = Cabang::factory()->create();

        $kasirA = User::factory()->kasir()->for($cabangA)->create();
        $canvassingB = Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabangB->id,
        ]);

        $service = app(VerifikasiCanvassingService::class);

        $this->expectException(AuthorizationException::class);
        $service->terima($canvassingB, $kasirA);
    }

    // ─── 6. Tab Filter & Empty State ───────────────────────────────────────────

    public function test_tab_filter_menampilkan_catatan_sesuai_status(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();
        $kurir = User::factory()->kurir()->for($cabang)->create(['name' => 'Kurir Gani']);

        $menunggu = Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
            'jumlah_galon' => 2,
        ]);

        $tervalidasi = Transaksi::factory()->canvassing(StatusTransaksi::Tervalidasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
            'jumlah_galon' => 6,
        ]);

        $ditolak = Transaksi::factory()->canvassing(StatusTransaksi::Ditolak)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
            'jumlah_galon' => 8,
        ]);

        $this->actingAs($kasir);

        // Tab default 'menunggu'
        Livewire::test('pages::kasir.canvassing')
            ->assertSee('#CV-'.$menunggu->id)
            ->assertDontSee('#CV-'.$tervalidasi->id)
            ->assertDontSee('#CV-'.$ditolak->id)
            // Pindah ke tab 'tervalidasi'
            ->set('tab', 'tervalidasi')
            ->assertDontSee('#CV-'.$menunggu->id)
            ->assertSee('#CV-'.$tervalidasi->id)
            ->assertDontSee('#CV-'.$ditolak->id)
            // Pindah ke tab 'ditolak'
            ->set('tab', 'ditolak')
            ->assertDontSee('#CV-'.$menunggu->id)
            ->assertDontSee('#CV-'.$tervalidasi->id)
            ->assertSee('#CV-'.$ditolak->id)
            // Pindah ke tab 'semua'
            ->set('tab', 'semua')
            ->assertSee('#CV-'.$menunggu->id)
            ->assertSee('#CV-'.$tervalidasi->id)
            ->assertSee('#CV-'.$ditolak->id);
    }

    public function test_empty_state_ditampilkan_ketika_tidak_ada_catatan_canvassing(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();

        $this->actingAs($kasir);

        Livewire::test('pages::kasir.canvassing')
            ->assertSeeHtml('data-test="empty-state"')
            ->assertSee('Belum ada catatan canvassing yang menunggu verifikasi dari kurir armada.');
    }

    public function test_alasan_penolakan_dengan_karakter_khusus_terescape_dengan_aman(): void
    {
        $cabang = Cabang::factory()->create();
        $kasir = User::factory()->kasir()->for($cabang)->create();
        $kurir = User::factory()->kurir()->for($cabang)->create();

        $canvassing = Transaksi::factory()->canvassing(StatusTransaksi::MenungguVerifikasi)->create([
            'cabang_id' => $cabang->id,
            'kurir_id' => $kurir->id,
        ]);

        $this->actingAs($kasir);

        $alasanBahaya = '<script>alert("xss")</script> & tanda kurung';

        Livewire::test('pages::kasir.canvassing')
            ->call('bukaTolak', $canvassing->id)
            ->set('alasanTolak', $alasanBahaya)
            ->call('simpanTolak');

        Livewire::test('pages::kasir.canvassing')
            ->set('tab', 'ditolak')
            ->assertDontSeeHtml('<script>alert("xss")</script>')
            ->assertSee(e($alasanBahaya), false);
    }
}
