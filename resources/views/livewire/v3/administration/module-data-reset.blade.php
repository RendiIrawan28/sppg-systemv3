<x-v3.shell :$unit :$navigation :$roleLabel title="Reset Data Modul" eyebrow="Khusus Super Admin">
    <div class="mx-auto max-w-[1050px] space-y-5">
        <section class="rounded-[28px] bg-[#081d3a] p-6 text-white sm:p-8">
            <p class="text-xs font-bold uppercase tracking-[.16em] text-rose-300">Penghapusan permanen</p>
            <h2 class="mt-2 text-2xl font-bold">Reset data Gudang atau Penerima Manfaat</h2>
            <p class="mt-2 text-sm leading-6 text-slate-300">Reset berlaku untuk Unit SPPG ini saja. Buat cadangan database terlebih dahulu. Dokumen foto lama tetap tersimpan di server untuk kebutuhan pemulihan manual; catatan audit reset tidak ikut dihapus.</p>
        </section>

        @if($actionMessage)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ $actionMessage }}</div>@endif
        @error('reset')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <label class="block max-w-md"><span class="mb-2 block text-sm font-bold text-slate-800">Pilih data yang akan direset</span><select wire:model.live="scope" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm">@foreach($labels as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></label>
            <p class="mt-4 text-sm text-slate-600">
                @if($scope === 'warehouse')
                    Menghapus seluruh saldo, lot, kartu stok/mutasi, stok awal, penerimaan, penyesuaian, dan pengambilan barang Pangan maupun Non-Pangan. Master barang, supplier, dan data pengadaan tetap ada.
                @else
                    Menghapus daftar penerima, riwayat impor, periode dan rinciannya, serta konfirmasi penerima harian. Sekolah, posyandu, dan kategori penerima tetap ada.
                @endif
            </p>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="font-bold text-slate-900">Pratinjau dampak</h3>
            @if($preview['missing'] !== [])
                <p class="mt-3 rounded-xl bg-rose-50 p-4 text-sm text-rose-700">Reset belum tersedia: struktur tabel belum lengkap ({{ implode(', ', $preview['missing']) }}).</p>
            @else
                <p class="mt-1 text-sm text-slate-500">{{ number_format(array_sum($preview['counts']), 0, ',', '.') }} baris data akan dihapus.</p>
                <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">@foreach($preview['counts'] as $table => $count)<div class="flex justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2 text-xs"><span class="break-all text-slate-600">{{ str($table)->replace('_', ' ')->title() }}</span><b class="text-slate-900">{{ number_format($count, 0, ',', '.') }}</b></div>@endforeach</div>
                @if($preview['blockers'] !== [])
                    <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><b>Reset ditahan agar modul lain tidak rusak.</b><p class="mt-1">Tinjau dan selesaikan keterkaitan berikut secara terpisah sebelum reset:</p><ul class="mt-2 list-inside list-disc">@foreach($preview['blockers'] as $label => $count)<li>{{ $label }} ({{ $count }})</li>@endforeach</ul></div>
                @endif
            @endif
        </section>

        <form wire:submit="resetModule" class="rounded-2xl border-2 border-rose-200 bg-white p-5 shadow-sm">
            <h3 class="font-bold text-rose-700">Konfirmasi reset</h3>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <label class="sm:col-span-2"><span class="mb-1 block text-xs font-semibold text-slate-700">Alasan reset *</span><textarea wire:model="reason" rows="2" class="w-full rounded-xl border-slate-200 px-3 py-2 text-sm" placeholder="Jelaskan alasan reset data ini"></textarea>@error('reason')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror</label>
                <label><span class="mb-1 block text-xs font-semibold text-slate-700">Ketik {{ $this->requiredConfirmation() }} *</span><input wire:model="confirmation" autocomplete="off" class="h-11 w-full rounded-xl border-slate-200 px-3 text-sm">@error('confirmation')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror</label>
                <label><span class="mb-1 block text-xs font-semibold text-slate-700">Kata sandi Super Admin *</span><input wire:model="password" type="password" autocomplete="current-password" class="h-11 w-full rounded-xl border-slate-200 px-3 text-sm">@error('password')<span class="mt-1 block text-xs text-rose-700">{{ $message }}</span>@enderror</label>
                <label class="flex items-center gap-2 sm:col-span-2"><input wire:model="backupConfirmed" type="checkbox" class="size-4 rounded border-slate-300"><span class="text-sm text-slate-700">Saya sudah menyiapkan cadangan database dan memahami data ini akan dihapus permanen.</span></label>
                @error('backupConfirmed')<span class="text-xs text-rose-700 sm:col-span-2">{{ $message }}</span>@enderror
            </div>
            <button type="submit" wire:loading.attr="disabled" @disabled($preview['missing'] !== [] || $preview['blockers'] !== [] || array_sum($preview['counts']) === 0) wire:confirm="Reset modul ini akan menghapus data secara permanen. Lanjutkan?" class="mt-5 h-11 rounded-xl bg-rose-600 px-5 text-sm font-bold text-white disabled:cursor-not-allowed disabled:opacity-50">Reset {{ $labels[$scope] ?? 'Data' }}</button>
        </form>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="font-bold text-slate-900">Riwayat reset</h3><div class="mt-4 space-y-3">@forelse($recentLogs as $log)<div class="rounded-xl border border-slate-100 p-3 text-sm"><b>{{ $log->record_label }}</b> · {{ $log->deleted_at?->translatedFormat('d M Y H:i') }}<p class="mt-1 text-xs text-slate-500">{{ $log->actor_name_snapshot }} · {{ array_sum($log->deleted_counts ?? []) }} baris · {{ $log->reason }}</p></div>@empty<p class="text-sm text-slate-500">Belum ada reset modul.</p>@endforelse</div></section>
    </div>
</x-v3.shell>
