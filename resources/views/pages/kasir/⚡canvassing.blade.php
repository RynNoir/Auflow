<?php

use App\Enums\Kanal;
use App\Enums\MetodePembayaran;
use App\Enums\Role;
use App\Enums\StatusTransaksi;
use App\Models\Transaksi;
use App\Models\User;
use App\Services\VerifikasiCanvassingService;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Verifikasi Catatan Canvassing')]
class extends Component {
    use WithPagination;

    /**
     * Tab status yang aktif: 'menunggu', 'tervalidasi', 'ditolak', atau 'semua'.
     */
    #[Url]
    public string $tab = 'menunggu';

    /**
     * State modal konfirmasi penerimaan (tervalidasi).
     */
    public bool $modalTerima = false;

    public ?int $catatanTerimaId = null;

    public string $catatanTerima = '';

    /**
     * State modal penolakan (ditolak dengan alasan wajib).
     */
    public bool $modalTolak = false;

    public ?int $catatanTolakId = null;

    public string $alasanTolak = '';

    public function mount(): void
    {
        $this->kasir();
        $this->normalkanTab();
    }

    public function hydrate(): void
    {
        $this->kasir();
    }

    public function updatedTab(): void
    {
        $this->normalkanTab();
        $this->resetPage();
    }

    /**
     * Definisi tab filter status.
     *
     * @return array<string, array{label: string, status: list<StatusTransaksi>|null}>
     */
    #[Computed]
    public function daftarTab(): array
    {
        return [
            'menunggu' => [
                'label' => __('Menunggu Verifikasi'),
                'status' => [StatusTransaksi::MenungguVerifikasi],
            ],
            'tervalidasi' => [
                'label' => StatusTransaksi::Tervalidasi->label(),
                'status' => [StatusTransaksi::Tervalidasi],
            ],
            'ditolak' => [
                'label' => StatusTransaksi::Ditolak->label(),
                'status' => [StatusTransaksi::Ditolak],
            ],
            'semua' => [
                'label' => __('Semua'),
                'status' => null,
            ],
        ];
    }

    /**
     * Hitungan catatan canvassing per tab untuk cabang kasir.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function jumlahPerTab(): array
    {
        $perStatus = $this->queryCanvassing()
            ->toBase()
            ->selectRaw('status, COUNT(*) as jumlah')
            ->groupBy('status')
            ->pluck('jumlah', 'status');

        $totalSemua = (int) $perStatus->sum();

        $hasil = [];
        foreach ($this->daftarTab as $kunci => $tab) {
            if ($tab['status'] === null) {
                $hasil[$kunci] = $totalSemua;
            } else {
                $hasil[$kunci] = collect($tab['status'])
                    ->sum(fn (StatusTransaksi $st): int => (int) ($perStatus[$st->value] ?? 0));
            }
        }

        return $hasil;
    }

    /**
     * Ringkasan statistik catatan canvassing yang menunggu verifikasi.
     *
     * @return array{menunggu_count: int, galon_count: int, setoran_sum: int}
     */
    #[Computed]
    public function ringkasanStat(): array
    {
        $agregat = $this->queryCanvassing()
            ->where('status', StatusTransaksi::MenungguVerifikasi)
            ->toBase()
            ->selectRaw('COUNT(*) as total_catatan, COALESCE(SUM(jumlah_galon), 0) as total_galon, COALESCE(SUM(total_harga), 0) as total_setoran')
            ->first();

        return [
            'menunggu_count' => (int) ($agregat?->total_catatan ?? 0),
            'galon_count' => (int) ($agregat?->total_galon ?? 0),
            'setoran_sum' => (int) ($agregat?->total_setoran ?? 0),
        ];
    }

    /**
     * Daftar catatan canvassing terpaginasi.
     *
     * @return LengthAwarePaginator<int, Transaksi>
     */
    #[Computed]
    public function canvassingList(): LengthAwarePaginator
    {
        $statusDipilih = $this->daftarTab[$this->tab]['status'];

        $query = $this->queryCanvassing()
            ->with(['kurir:id,name', 'verifikator:id,name', 'riwayatStatus'])
            ->when($statusDipilih !== null, fn (Builder $q) => $q->whereIn('status', $statusDipilih))
            ->orderBy('id', $this->tab === 'menunggu' ? 'asc' : 'desc');

        $hasil = $query->paginate(10);

        if ($hasil->isEmpty() && $hasil->currentPage() > 1) {
            $this->setPage($hasil->lastPage());
            $hasil = $query->paginate(10);
        }

        return $hasil;
    }

    /**
     * Detail catatan canvassing untuk modal terima.
     */
    #[Computed]
    public function catatanTerimaItem(): ?Transaksi
    {
        if ($this->catatanTerimaId === null) {
            return null;
        }

        return $this->queryCanvassing()->with('kurir:id,name')->find($this->catatanTerimaId);
    }

    /**
     * Detail catatan canvassing untuk modal tolak.
     */
    #[Computed]
    public function catatanTolakItem(): ?Transaksi
    {
        if ($this->catatanTolakId === null) {
            return null;
        }

        return $this->queryCanvassing()->with('kurir:id,name')->find($this->catatanTolakId);
    }

    /**
     * Buka modal konfirmasi penerimaan catatan canvassing.
     */
    public function bukaTerima(int $catatanId): void
    {
        $this->resetValidation();
        $catatan = $this->canvassingCabang($catatanId);

        if ($catatan->status !== StatusTransaksi::MenungguVerifikasi) {
            Flux::toast(variant: 'danger', text: __('Catatan canvassing ini sudah diproses sebelumnya.'));

            return;
        }

        $this->catatanTerimaId = $catatan->id;
        $this->catatanTerima = '';
        $this->modalTerima = true;
    }

    /**
     * Eksekusi penerimaan verifikasi canvassing.
     */
    public function simpanTerima(): void
    {
        $this->resetValidation();

        $catatan = $this->canvassingCabang((int) $this->catatanTerimaId);

        $hasil = app(VerifikasiCanvassingService::class)->terima(
            $catatan,
            $this->kasir(),
            $this->catatanTerima
        );

        $this->reset('modalTerima', 'catatanTerimaId', 'catatanTerima');
        Flux::toast(variant: 'success', text: __('Catatan canvassing #CV-:id berhasil diverifikasi (Tervalidasi).', ['id' => $hasil->id]));
    }

    /**
     * Buka modal penolakan catatan canvassing.
     */
    public function bukaTolak(int $catatanId): void
    {
        $this->resetValidation();
        $catatan = $this->canvassingCabang($catatanId);

        if ($catatan->status !== StatusTransaksi::MenungguVerifikasi) {
            Flux::toast(variant: 'danger', text: __('Catatan canvassing ini sudah diproses sebelumnya.'));

            return;
        }

        $this->catatanTolakId = $catatan->id;
        $this->alasanTolak = '';
        $this->modalTolak = true;
    }

    /**
     * Eksekusi penolakan verifikasi canvassing dengan alasan.
     */
    public function simpanTolak(): void
    {
        $this->validate([
            'alasanTolak' => ['required', 'string', 'min:5', 'max:500'],
        ], [
            'alasanTolak.required' => __('Alasan penolakan wajib diisi.'),
            'alasanTolak.min' => __('Alasan penolakan minimal 5 karakter.'),
            'alasanTolak.max' => __('Alasan penolakan maksimal 500 karakter.'),
        ]);

        $catatan = $this->canvassingCabang((int) $this->catatanTolakId);

        $hasil = app(VerifikasiCanvassingService::class)->tolak(
            $catatan,
            $this->kasir(),
            $this->alasanTolak
        );

        $this->reset('modalTolak', 'catatanTolakId', 'alasanTolak');
        Flux::toast(variant: 'success', text: __('Catatan canvassing #CV-:id ditolak.', ['id' => $hasil->id]));
    }

    /**
     * Query dasar catatan canvassing di cabang kasir.
     *
     * @return Builder<Transaksi>
     */
    private function queryCanvassing(): Builder
    {
        return Transaksi::query()
            ->where('cabang_id', $this->kasir()->cabang_id)
            ->where('jenis_kanal', Kanal::Canvassing);
    }

    /**
     * Cari catatan canvassing spesifik di cabang kasir.
     */
    private function canvassingCabang(int $id): Transaksi
    {
        return $this->queryCanvassing()->findOrFail($id);
    }

    /**
     * Otorisasi: hanya Kasir aktif yang memiliki cabang.
     */
    private function kasir(): User
    {
        /** @var User|null $user */
        $user = Auth::user();

        abort_unless(
            $user instanceof User
            && $user->role === Role::Kasir
            && $user->is_active
            && $user->cabang_id !== null,
            403
        );

        return $user;
    }

    private function normalkanTab(): void
    {
        if (! array_key_exists($this->tab, $this->daftarTab)) {
            $this->tab = 'menunggu';
        }
    }
}; ?>

<section class="flex w-full flex-col gap-6" data-test="halaman-verifikasi-canvassing">
    {{-- Header Halaman --}}
    <div class="flex flex-col gap-4 rounded-2xl bg-white p-6 shadow-card md:flex-row md:items-end md:justify-between">
        <div class="flex flex-col gap-1">
            <span class="text-xs font-semibold uppercase tracking-wider text-primary">{{ __('Loket Kasir • Kanal Canvassing') }}</span>
            <flux:heading size="xl" level="1" class="text-deep!">{{ __('Verifikasi Catatan Canvassing') }}</flux:heading>
            <flux:text class="text-muted!">
                {{ __('Periksa dan verifikasi setoran penjualan air galon keliling oleh kurir armada di :cabang.', ['cabang' => auth()->user()?->cabang?->nama ?? '-']) }}
            </flux:text>
        </div>

        <div class="flex items-center gap-2 rounded-xl bg-surface-low px-3 py-2 text-xs font-medium text-ink">
            <flux:icon.building-storefront variant="mini" class="text-primary size-4" />
            <span>{{ auth()->user()?->cabang?->nama ?? '-' }}</span>
        </div>
    </div>

    {{-- Kartu Ringkasan (Stat Cards) --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="flex items-center justify-between rounded-2xl bg-white p-5 shadow-card" data-test="stat-menunggu">
            <div class="flex flex-col gap-1">
                <span class="text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Menunggu Verifikasi') }}</span>
                <span class="text-2xl font-black text-ink">{{ number_format($this->ringkasanStat['menunggu_count']) }}</span>
                <span class="text-xs text-muted">{{ __('Catatan setoran kurir') }}</span>
            </div>
            <div class="flex aspect-square size-12 items-center justify-center rounded-2xl bg-warning-soft text-warning">
                <flux:icon.clock variant="mini" class="size-6" />
            </div>
        </div>

        <div class="flex items-center justify-between rounded-2xl bg-white p-5 shadow-card" data-test="stat-galon">
            <div class="flex flex-col gap-1">
                <span class="text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Galon Menunggu') }}</span>
                <span class="text-2xl font-black text-primary">{{ number_format($this->ringkasanStat['galon_count']) }}</span>
                <span class="text-xs text-muted">{{ __('Galon isi ulang terantar') }}</span>
            </div>
            <div class="flex aspect-square size-12 items-center justify-center rounded-2xl bg-primary-soft text-primary">
                <flux:icon.archive-box variant="mini" class="size-6" />
            </div>
        </div>

        <div class="flex items-center justify-between rounded-2xl bg-white p-5 shadow-card" data-test="stat-setoran">
            <div class="flex flex-col gap-1">
                <span class="text-xs font-semibold uppercase tracking-wider text-muted">{{ __('Nilai Setoran Menunggu') }}</span>
                <span class="text-2xl font-black text-success">Rp{{ number_format($this->ringkasanStat['setoran_sum'], 0, ',', '.') }}</span>
                <span class="text-xs text-muted">{{ __('Tarif Rp7.000 / galon') }}</span>
            </div>
            <div class="flex aspect-square size-12 items-center justify-center rounded-2xl bg-success-soft text-success">
                <flux:icon.banknotes variant="mini" class="size-6" />
            </div>
        </div>
    </div>

    {{-- Filter Status Tab --}}
    <div class="flex flex-wrap items-center gap-2 border-b border-line pb-3" data-test="filter-tabs">
        @foreach ($this->daftarTab as $kunci => $info)
            @php
                $aktif = $this->tab === $kunci;
                $hitung = $this->jumlahPerTab[$kunci] ?? 0;
            @endphp
            <button
                type="button"
                wire:click="$set('tab', '{{ $kunci }}')"
                class="flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition {{ $aktif ? 'bg-primary text-white shadow-xs' : 'bg-white text-muted hover:bg-surface-low hover:text-ink shadow-card' }}"
                data-test="tab-{{ $kunci }}"
            >
                <span>{{ $info['label'] }}</span>
                <span class="rounded-full px-2 py-0.5 text-xs font-bold {{ $aktif ? 'bg-white/20 text-white' : 'bg-surface text-ink' }}">
                    {{ $hitung }}
                </span>
            </button>
        @endforeach
    </div>

    {{-- Tabel Catatan Canvassing --}}
    <div class="overflow-hidden rounded-2xl bg-white shadow-card">
        @if ($this->canvassingList->isEmpty())
            <div class="flex flex-col items-center justify-center gap-3 p-12 text-center" data-test="empty-state">
                <div class="flex aspect-square size-14 items-center justify-center rounded-2xl bg-surface-low text-muted">
                    <flux:icon.clipboard-document-check class="size-7" />
                </div>
                <div class="flex flex-col gap-1">
                    <span class="text-base font-bold text-ink">{{ __('Tidak ada catatan canvassing') }}</span>
                    <span class="text-sm text-muted">
                        @if ($this->tab === 'menunggu')
                            {{ __('Belum ada catatan canvassing yang menunggu verifikasi dari kurir armada.') }}
                        @else
                            {{ __('Tidak ada catatan canvassing pada status ini.') }}
                        @endif
                    </span>
                </div>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-ink" data-test="tabel-canvassing">
                    <thead class="border-b border-line bg-surface-low text-xs font-bold uppercase tracking-wider text-muted">
                        <tr>
                            <th scope="col" class="px-6 py-4">{{ __('ID & Waktu') }}</th>
                            <th scope="col" class="px-6 py-4">{{ __('Kurir Armada') }}</th>
                            <th scope="col" class="px-6 py-4 text-center">{{ __('Jumlah Galon') }}</th>
                            <th scope="col" class="px-6 py-4">{{ __('Total Setoran') }}</th>
                            <th scope="col" class="px-6 py-4">{{ __('Metode') }}</th>
                            <th scope="col" class="px-6 py-4">{{ __('Status') }}</th>
                            <th scope="col" class="px-6 py-4 text-right">{{ __('Aksi / Info') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        @foreach ($this->canvassingList as $item)
                            <tr wire:key="canvassing-{{ $item->id }}" class="hover:bg-surface/50 transition" data-test="row-canvassing-{{ $item->id }}">
                                {{-- ID & Waktu --}}
                                <td class="px-6 py-4 align-top">
                                    <div class="flex flex-col leading-tight">
                                        <span class="font-bold text-deep">#CV-{{ $item->id }}</span>
                                        <span class="text-xs text-muted">{{ $item->created_at?->translatedFormat('d M Y, H:i') ?? $item->tanggal->translatedFormat('d M Y') }}</span>
                                    </div>
                                </td>

                                {{-- Kurir --}}
                                <td class="px-6 py-4 align-top">
                                    <div class="flex items-center gap-2">
                                        <div class="flex aspect-square size-7 items-center justify-center rounded-lg bg-surface-low text-primary font-bold text-xs">
                                            <flux:icon.truck variant="mini" class="size-4" />
                                        </div>
                                        <div class="flex flex-col leading-tight">
                                            <span class="font-semibold text-ink" data-test="nama-kurir-{{ $item->id }}">{{ $item->kurir?->name ?? __('Kurir tidak tercatat') }}</span>
                                            <span class="text-[11px] text-muted">{{ __('Armada Cabang') }}</span>
                                        </div>
                                    </div>
                                </td>

                                {{-- Jumlah Galon --}}
                                <td class="px-6 py-4 align-top text-center">
                                    <span class="inline-flex items-center justify-center rounded-xl bg-surface-low px-3 py-1 text-sm font-bold text-primary" data-test="jumlah-galon-{{ $item->id }}">
                                        {{ $item->jumlah_galon }} Galon
                                    </span>
                                </td>

                                {{-- Total Setoran --}}
                                <td class="px-6 py-4 align-top">
                                    <div class="flex flex-col leading-tight">
                                        <span class="font-extrabold text-ink" data-test="total-harga-{{ $item->id }}">
                                            Rp{{ number_format($item->total_harga, 0, ',', '.') }}
                                        </span>
                                        <span class="text-[11px] text-muted">Rp7.000 / galon</span>
                                    </div>
                                </td>

                                {{-- Metode Bayar --}}
                                <td class="px-6 py-4 align-top">
                                    @if ($item->metode_bayar === MetodePembayaran::Tunai)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700" data-test="metode-bayar-{{ $item->id }}">
                                            <flux:icon.banknotes variant="mini" class="size-3.5" />
                                            {{ __('Tunai') }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700" data-test="metode-bayar-{{ $item->id }}">
                                            <flux:icon.qr-code variant="mini" class="size-3.5" />
                                            {{ __('QR Statis') }}
                                        </span>
                                    @endif
                                </td>

                                {{-- Status --}}
                                <td class="px-6 py-4 align-top">
                                    @if ($item->status === StatusTransaksi::MenungguVerifikasi)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700" data-test="status-badge-{{ $item->id }}">
                                            <span class="size-1.5 rounded-full bg-amber-500"></span>
                                            {{ $item->status->label() }}
                                        </span>
                                    @elseif ($item->status === StatusTransaksi::Tervalidasi)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700" data-test="status-badge-{{ $item->id }}">
                                            <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                            {{ $item->status->label() }}
                                        </span>
                                    @elseif ($item->status === StatusTransaksi::Ditolak)
                                        <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-2.5 py-1 text-xs font-semibold text-rose-700" data-test="status-badge-{{ $item->id }}">
                                            <span class="size-1.5 rounded-full bg-rose-500"></span>
                                            {{ $item->status->label() }}
                                        </span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-surface-low px-2.5 py-1 text-xs font-medium text-muted">
                                            {{ $item->status->label() }}
                                        </span>
                                    @endif
                                </td>

                                {{-- Aksi / Info --}}
                                <td class="px-6 py-4 align-top text-right">
                                    @if ($item->status === StatusTransaksi::MenungguVerifikasi)
                                        <div class="flex items-center justify-end gap-2">
                                            <flux:button
                                                type="button"
                                                variant="primary"
                                                size="sm"
                                                icon="check"
                                                wire:click="bukaTerima({{ $item->id }})"
                                                data-test="tombol-terima-{{ $item->id }}"
                                            >
                                                {{ __('Terima') }}
                                            </flux:button>

                                            <flux:button
                                                type="button"
                                                variant="danger"
                                                size="sm"
                                                icon="x-mark"
                                                wire:click="bukaTolak({{ $item->id }})"
                                                data-test="tombol-tolak-{{ $item->id }}"
                                            >
                                                {{ __('Tolak') }}
                                            </flux:button>
                                        </div>
                                    @elseif ($item->status === StatusTransaksi::Tervalidasi)
                                        <div class="flex flex-col text-right leading-tight">
                                            <span class="text-xs font-semibold text-ink">{{ __('Diverifikasi') }}</span>
                                            <span class="text-[11px] text-muted">{{ $item->verifikator?->name ?? __('Kasir') }}</span>
                                        </div>
                                    @elseif ($item->status === StatusTransaksi::Ditolak)
                                        @php
                                            $riwayatTolak = $item->riwayatStatus->where('status_baru', StatusTransaksi::Ditolak)->last();
                                        @endphp
                                        <div class="flex flex-col text-right leading-tight max-w-xs ml-auto">
                                            <span class="text-xs font-bold text-danger">{{ __('Ditolak') }}</span>
                                            @if ($riwayatTolak?->catatan)
                                                <span class="text-[11px] text-muted truncate" title="{{ $riwayatTolak->catatan }}" data-test="alasan-ditolak-{{ $item->id }}">
                                                    {{ $riwayatTolak->catatan }}
                                                </span>
                                            @endif
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="border-t border-line p-4">
                {{ $this->canvassingList->links() }}
            </div>
        @endif
    </div>

    {{-- Modal Konfirmasi Terima Setoran Canvassing --}}
    <flux:modal wire:model.self="modalTerima" class="w-full md:w-md" data-test="modal-terima">
        <form wire:submit="simpanTerima" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg">{{ __('Terima Setoran Canvassing') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Verifikasi penerimaan uang setoran canvassing #CV-:id menjadi Tervalidasi.', ['id' => $catatanTerimaId]) }}
                </flux:text>
            </div>

            @if ($this->catatanTerimaItem)
                <div class="rounded-xl border border-line bg-surface-low/50 p-4 text-xs flex flex-col gap-2">
                    <div class="flex justify-between">
                        <span class="text-muted">{{ __('Kurir Armada') }}:</span>
                        <span class="font-bold text-ink">{{ $this->catatanTerimaItem->kurir?->name ?? '-' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted">{{ __('Jumlah Galon') }}:</span>
                        <span class="font-bold text-ink">{{ $this->catatanTerimaItem->jumlah_galon }} Galon</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted">{{ __('Metode Bayar') }}:</span>
                        <span class="font-bold text-ink">{{ $this->catatanTerimaItem->metode_bayar->label() }}</span>
                    </div>
                    <div class="border-t border-line pt-2 flex justify-between text-sm">
                        <span class="font-semibold text-ink">{{ __('Total Setoran Fisik/QR') }}:</span>
                        <span class="font-extrabold text-success">Rp{{ number_format($this->catatanTerimaItem->total_harga, 0, ',', '.') }}</span>
                    </div>
                </div>
            @endif

            <flux:field>
                <flux:label>{{ __('Catatan verifikasi (opsional)') }}</flux:label>
                <flux:input wire:model="catatanTerima" placeholder="{{ __('Misal: Setoran tunai sesuai fisik') }}" maxlength="255" data-test="catatan-terima" />
                <flux:error name="catatanTerima" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" data-test="konfirmasi-terima">
                    <span wire:loading.remove wire:target="simpanTerima">{{ __('Terima Setoran') }}</span>
                    <span wire:loading wire:target="simpanTerima">{{ __('Memproses...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Modal Penolakan Setoran Canvassing --}}
    <flux:modal wire:model.self="modalTolak" class="w-full md:w-md" data-test="modal-tolak">
        <form wire:submit="simpanTolak" class="flex flex-col gap-5">
            <div>
                <flux:heading size="lg" class="text-danger!">{{ __('Tolak Catatan Canvassing') }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Catatan #CV-:id akan ditandai Ditolak. Harap cantumkan alasan penolakan secara jelas.', ['id' => $catatanTolakId]) }}
                </flux:text>
            </div>

            @if ($this->catatanTolakItem)
                <div class="rounded-xl border border-line bg-surface-low/50 p-4 text-xs flex flex-col gap-2">
                    <div class="flex justify-between">
                        <span class="text-muted">{{ __('Kurir Armada') }}:</span>
                        <span class="font-bold text-ink">{{ $this->catatanTolakItem->kurir?->name ?? '-' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-muted">{{ __('Setoran yang Ditolak') }}:</span>
                        <span class="font-bold text-danger">
                            {{ $this->catatanTolakItem->jumlah_galon }} Galon (Rp{{ number_format($this->catatanTolakItem->total_harga, 0, ',', '.') }})
                        </span>
                    </div>
                </div>
            @endif

            <flux:field>
                <flux:label>{{ __('Alasan penolakan (wajib)') }}</flux:label>
                <flux:textarea
                    wire:model="alasanTolak"
                    rows="3"
                    placeholder="{{ __('Contoh: Jumlah uang setoran fisik kurang Rp7.000 atau bukti transfer QR tidak valid.') }}"
                    maxlength="500"
                    data-test="alasan-tolak"
                />
                <flux:error name="alasanTolak" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button type="button" variant="ghost">{{ __('Batal') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="danger" wire:loading.attr="disabled" data-test="konfirmasi-tolak">
                    <span wire:loading.remove wire:target="simpanTolak">{{ __('Tolak Catatan') }}</span>
                    <span wire:loading wire:target="simpanTolak">{{ __('Memproses...') }}</span>
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
