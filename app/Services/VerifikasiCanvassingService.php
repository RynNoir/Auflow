<?php

namespace App\Services;

use App\Enums\Kanal;
use App\Enums\Role;
use App\Enums\StatusTransaksi;
use App\Models\RiwayatStatus;
use App\Models\Transaksi;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Service verifikasi catatan penjualan canvassing oleh Kasir (PRD §6, §7.F, ERD v1.1).
 *
 * Mengelola transisi status catatan canvassing dari `menunggu_verifikasi`
 * menjadi `tervalidasi` (diterima) atau `ditolak` (wajib alasan penolakan).
 * Mengaudit setiap perubahan ke tabel `riwayat_status`.
 */
class VerifikasiCanvassingService
{
    public const PANJANG_MINIMAL_ALASAN = 5;

    public const PANJANG_MAKSIMAL_ALASAN = 500;

    /**
     * Terima catatan canvassing kurir: status berubah menjadi tervalidasi.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function terima(Transaksi $transaksi, User $kasir, ?string $catatan = null): Transaksi
    {
        return DB::transaction(function () use ($transaksi, $kasir, $catatan): Transaksi {
            $terkunci = $this->kunci($transaksi);

            $this->pastikanKasirCabang($terkunci, $kasir);
            $this->pastikanCanvassing($terkunci);
            $this->pastikanMenungguVerifikasi($terkunci);

            $statusLama = $terkunci->status;
            $terkunci->status = StatusTransaksi::Tervalidasi;
            $terkunci->diverifikasi_oleh = $kasir->id;
            $terkunci->save();

            $catatanBersih = $catatan !== null && trim($catatan) !== '' ? trim($catatan) : null;
            $this->catatRiwayat($terkunci, $statusLama, $kasir, $catatanBersih);

            return $terkunci;
        });
    }

    /**
     * Tolak catatan canvassing kurir: status berubah menjadi ditolak dan wajib menyertakan alasan.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function tolak(Transaksi $transaksi, User $kasir, string $alasan): Transaksi
    {
        $alasanBersih = trim($alasan);

        if (mb_strlen($alasanBersih) < self::PANJANG_MINIMAL_ALASAN || mb_strlen($alasanBersih) > self::PANJANG_MAKSIMAL_ALASAN) {
            throw ValidationException::withMessages([
                'alasan' => __('Alasan penolakan wajib diisi, minimal :min dan maksimal :max karakter.', [
                    'min' => self::PANJANG_MINIMAL_ALASAN,
                    'max' => self::PANJANG_MAKSIMAL_ALASAN,
                ]),
            ]);
        }

        return DB::transaction(function () use ($transaksi, $kasir, $alasanBersih): Transaksi {
            $terkunci = $this->kunci($transaksi);

            $this->pastikanKasirCabang($terkunci, $kasir);
            $this->pastikanCanvassing($terkunci);
            $this->pastikanMenungguVerifikasi($terkunci);

            $statusLama = $terkunci->status;
            $terkunci->status = StatusTransaksi::Ditolak;
            $terkunci->diverifikasi_oleh = $kasir->id;
            $terkunci->save();

            $this->catatRiwayat($terkunci, $statusLama, $kasir, $alasanBersih);

            return $terkunci;
        });
    }

    /**
     * Kunci baris transaksi agar tidak diverifikasi bersamaan oleh staf lain.
     */
    private function kunci(Transaksi $transaksi): Transaksi
    {
        return Transaksi::query()->lockForUpdate()->findOrFail($transaksi->id);
    }

    /**
     * Catat mutasi status ke tabel riwayat_status.
     */
    private function catatRiwayat(
        Transaksi $transaksi,
        StatusTransaksi $statusLama,
        User $kasir,
        ?string $catatan = null
    ): RiwayatStatus {
        $riwayat = new RiwayatStatus;
        $riwayat->transaksi_id = $transaksi->id;
        $riwayat->status_lama = $statusLama;
        $riwayat->status_baru = $transaksi->status;
        $riwayat->diubah_oleh_staff = $kasir->id;
        $riwayat->diubah_oleh_pelanggan = null;
        $riwayat->catatan = $catatan;
        $riwayat->save();

        return $riwayat;
    }

    /**
     * Hanya Kasir aktif di cabang yang sama yang boleh memverifikasi.
     *
     * @throws AuthorizationException
     */
    private function pastikanKasirCabang(Transaksi $transaksi, User $kasir): void
    {
        $berhak = $kasir->role === Role::Kasir
            && $kasir->is_active
            && $kasir->cabang_id !== null
            && $kasir->cabang_id === $transaksi->cabang_id;

        if (! $berhak) {
            throw new AuthorizationException(__('Anda tidak berhak memverifikasi transaksi canvassing ini.'));
        }
    }

    /**
     * Pastikan jenis kanal adalah canvassing.
     *
     * @throws ValidationException
     */
    private function pastikanCanvassing(Transaksi $transaksi): void
    {
        if ($transaksi->jenis_kanal !== Kanal::Canvassing) {
            throw ValidationException::withMessages([
                'status' => __('Transaksi ini bukan transaksi canvassing.'),
            ]);
        }
    }

    /**
     * Pastikan status masih menunggu verifikasi.
     *
     * @throws ValidationException
     */
    private function pastikanMenungguVerifikasi(Transaksi $transaksi): void
    {
        if ($transaksi->status !== StatusTransaksi::MenungguVerifikasi) {
            throw ValidationException::withMessages([
                'status' => __('Catatan canvassing ini sudah diproses sebelumnya (:status).', [
                    'status' => $transaksi->status->label(),
                ]),
            ]);
        }
    }
}
