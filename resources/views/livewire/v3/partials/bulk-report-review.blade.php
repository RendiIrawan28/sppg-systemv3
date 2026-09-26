@if($bulkReviewCount > 0)
    <section class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-800 dark:bg-sky-950/40">
        <div>
            <h3 class="text-sm font-bold text-sky-950 dark:text-sky-100">{{ $bulkReviewCount }} laporan menunggu persetujuan tahap Anda</h3>
            <p class="mt-1 text-xs text-sky-800 dark:text-sky-300">Hanya laporan pada tanggal yang dipilih. Setiap laporan tetap menjalani pemeriksaan dan pencatatan riwayat seperti verifikasi satuan.</p>
        </div>
        <button type="button" wire:click="approveAll" wire:confirm="Setujui {{ $bulkReviewCount }} laporan pada tanggal ini sekaligus? Jika satu laporan gagal diperiksa, seluruh tindakan dibatalkan." wire:loading.attr="disabled" wire:target="approveAll" class="h-11 rounded-xl bg-sky-700 px-5 text-xs font-bold text-white hover:bg-sky-800 disabled:opacity-60">
            Setujui semua ({{ $bulkReviewCount }})
        </button>
    </section>
@endif
@error('bulkReview')<p class="rounded-xl bg-rose-50 p-3 text-sm font-semibold text-rose-700">{{ $message }}</p>@enderror
