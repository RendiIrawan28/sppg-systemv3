<x-v3.shell :$unit :$navigation :$roleLabel title="Pembersihan Data Uji" eyebrow="Khusus Super Admin">
    <div class="mx-auto max-w-[1200px] space-y-5">
        <section class="overflow-hidden rounded-[28px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-8">
            <p class="text-xs font-bold uppercase tracking-[.18em] text-rose-300">Mode pengujian</p>
            <h2 class="mt-3 text-2xl font-bold">Hapus data transaksi yang salah meskipun sudah dikunci</h2>
            <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-300">Pilih satu dokumen utama. Sistem ikut membersihkan rincian, histori, dokumentasi, dan transaksi turunannya. Akun pengguna, role, konfigurasi, serta master data tidak dapat dihapus dari halaman ini.</p>
        </section>

        @if($actionMessage)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ $actionMessage }}</div>@endif
        @error('cleanup')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="grid gap-4 lg:grid-cols-[1.4fr_1fr_1fr_1.5fr]">
                <label><span class="mb-1 block text-xs font-semibold text-slate-600">Jenis data</span><select wire:model.live="recordType" class="h-11 w-full rounded-xl border-slate-200 text-sm">@foreach($definitions as $key => $item)<option value="{{ $key }}">{{ $item['label'] }}</option>@endforeach</select></label>
                <label><span class="mb-1 block text-xs font-semibold text-slate-600">Dari tanggal</span><input wire:model.live="dateFrom" type="date" class="h-11 w-full rounded-xl border-slate-200 text-sm"></label>
                <label><span class="mb-1 block text-xs font-semibold text-slate-600">Sampai tanggal</span><input wire:model.live="dateTo" type="date" class="h-11 w-full rounded-xl border-slate-200 text-sm"></label>
                <label><span class="mb-1 block text-xs font-semibold text-slate-600">Cari nomor/nama/ID</span><input wire:model.live.debounce.400ms="search" class="h-11 w-full rounded-xl border-slate-200 px-3 text-sm" placeholder="Ketik pencarian"></label>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 p-5"><h3 class="font-bold text-slate-900">{{ $definition['label'] }}</h3><p class="mt-1 text-xs text-slate-500">Maksimal 100 data terbaru sesuai filter.</p></div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[780px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr><th class="px-5 py-3">ID</th><th class="px-5 py-3">Nomor</th><th class="px-5 py-3">Keterangan</th><th class="px-5 py-3">Tanggal</th><th class="px-5 py-3">Status</th><th class="px-5 py-3 text-right">Tindakan</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($records as $record)
                            <tr wire:key="cleanup-row-{{ $recordType }}-{{ $record->getKey() }}">
                                <td class="px-5 py-4 font-mono text-xs text-slate-500">{{ $record->getKey() }}</td>
                                <td class="px-5 py-4 font-semibold text-slate-900">{{ $this->displayValue($record->getAttribute($definition['number'])) }}</td>
                                <td class="max-w-[320px] truncate px-5 py-4 text-slate-600">{{ $this->displayValue($record->getAttribute($definition['title'])) }}</td>
                                <td class="px-5 py-4 text-slate-600">{{ $this->displayDate($record->getAttribute($definition['date'])) }}</td>
                                <td class="px-5 py-4"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">{{ $this->displayValue($record->getAttribute('status') ?? $record->getAttribute('state')) }}</span></td>
                                <td class="px-5 py-4 text-right"><button wire:click="selectForDelete({{ $record->getKey() }})" class="rounded-xl border border-rose-200 px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50">Pilih untuk dihapus</button></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-12 text-center text-sm text-slate-400">Tidak ada data pada filter ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if($selected)
            <section class="rounded-2xl border-2 border-rose-300 bg-rose-50 p-5 shadow-sm">
                <div class="flex flex-col justify-between gap-3 sm:flex-row"><div><p class="text-xs font-bold uppercase tracking-[.15em] text-rose-600">Konfirmasi penghapusan permanen</p><h3 class="mt-1 text-lg font-bold text-slate-900">{{ $definition['label'] }} #{{ $selected->getKey() }}</h3><p class="mt-1 text-sm text-slate-600">{{ $this->displayValue($selected->getAttribute($definition['number'])) }} · {{ $this->displayValue($selected->getAttribute($definition['title'])) }}</p></div><button wire:click="cancelDelete" class="self-start text-xs font-bold text-slate-500">Batal</button></div>
                <div class="mt-5 grid gap-4 md:grid-cols-2">
                    <label><span class="mb-1 block text-xs font-semibold text-slate-700">Alasan pembersihan *</span><textarea wire:model="reason" rows="3" class="w-full rounded-xl border-rose-200 px-3 py-2 text-sm" placeholder="Jelaskan data yang salah dan alasan dihapus"></textarea>@error('reason')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror</label>
                    <label><span class="mb-1 block text-xs font-semibold text-slate-700">Ketik <strong>HAPUS DATA</strong> *</span><input wire:model="confirmation" class="h-11 w-full rounded-xl border-rose-200 px-3 text-sm" autocomplete="off">@error('confirmation')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror</label>
                </div>
                <div class="mt-4 flex justify-end"><button wire:click="purge" wire:loading.attr="disabled" wire:confirm="Data utama dan seluruh data terkait akan dihapus permanen. Lanjutkan?" class="h-11 rounded-xl bg-rose-600 px-5 text-sm font-bold text-white disabled:opacity-50"><span wire:loading.remove wire:target="purge">Hapus permanen</span><span wire:loading wire:target="purge">Sedang membersihkan...</span></button></div>
            </section>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="font-bold text-slate-900">Riwayat pembersihan</h3><p class="mt-1 text-xs text-slate-500">Log ini tidak ikut terhapus dan menjadi bukti tindakan Super Admin.</p>
            <div class="mt-4 space-y-3">@forelse($logs as $log)<article class="rounded-xl border border-slate-200 p-4"><div class="flex flex-col justify-between gap-2 sm:flex-row"><div><p class="font-semibold text-slate-900">{{ $log->record_label }} · {{ $log->source_number ?: '#'.$log->source_id }}</p><p class="mt-1 text-sm text-slate-600">{{ $log->reason }}</p><p class="mt-2 text-xs text-slate-400">{{ $log->actor_name_snapshot }} · {{ $log->deleted_at?->translatedFormat('d M Y, H:i') }}</p></div><span class="h-fit rounded-full bg-rose-50 px-3 py-1 text-xs font-bold text-rose-700">{{ array_sum($log->deleted_counts ?? []) }} baris</span></div></article>@empty<p class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-400">Belum ada pembersihan data.</p>@endforelse</div>
        </section>
    </div>
</x-v3.shell>
