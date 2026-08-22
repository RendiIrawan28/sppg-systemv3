<x-v3.shell :$unit :$navigation :$roleLabel title="Pengambilan Ompreng" eyebrow="Kegiatan siang hari">
    <div class="mx-auto max-w-[1450px] space-y-5 text-slate-900 dark:text-slate-100">
        <x-v3.flash-alert />

        <section class="rounded-[28px] bg-[#081d3a] p-6 text-white">
            <p class="text-xs font-bold uppercase tracking-[.18em] text-cyan-200">Daftar otomatis dari pengantaran</p>
            <h2 class="mt-2 text-2xl font-bold">Ambil ompreng setelah penerima selesai makan</h2>
            <p class="mt-2 max-w-4xl text-sm text-slate-300">Sekolah dan Posyandu muncul otomatis setelah makanan berhasil diantar. Driver pengambil tidak harus sama dengan driver pengantar.</p>
        </section>

        @if(!$activeRun && $canOperate)
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
                <h3 class="font-bold">Mulai kegiatan pengambilan</h3>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Nama driver otomatis mengikuti akun yang login. Data kendaraan dapat diisi bila diperlukan.</p>
                <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    <input wire:model="kernetName" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="Nama kernet (opsional)">
                    <input wire:model="vehicleName" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="Kendaraan (opsional)">
                    <input wire:model="vehiclePlate" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm uppercase dark:border-slate-700 dark:bg-slate-950" placeholder="Nomor polisi (opsional)">
                    <input wire:model="runNotes" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="Catatan kegiatan">
                </div>
                <div class="mt-4 flex justify-end"><button type="button" wire:click="startRun" class="h-11 rounded-xl bg-sky-600 px-5 text-xs font-bold text-white hover:bg-sky-700">Mulai pengambilan</button></div>
            </section>
        @endif

        @if($activeRun)
            <section class="rounded-2xl border border-sky-200 bg-sky-50 p-5 dark:border-sky-400/25 dark:bg-sky-500/10">
                <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
                    <div><p class="text-xs font-bold uppercase tracking-[.14em] text-sky-700 dark:text-sky-300">Kegiatan aktif {{ $activeRun->run_number }}</p><h3 class="mt-1 text-lg font-bold text-sky-950 dark:text-sky-100">Driver {{ $activeRun->driver_name_snapshot }}</h3><p class="mt-1 text-xs text-sky-700 dark:text-sky-300">Sudah dibawa: {{ number_format($activeRun->total_collected, 0, ',', '.') }} ompreng dari {{ $activeRun->items->count() }} pencatatan.</p></div>
                    <button type="button" wire:click="returnToSppg" wire:confirm="Pastikan seluruh ompreng yang sudah diambil berada di kendaraan. Kembali ke SPPG sekarang?" class="h-11 rounded-xl bg-emerald-600 px-5 text-xs font-bold text-white hover:bg-emerald-700">Kembali ke SPPG</button>
                </div>
            </section>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
            <div class="flex items-center justify-between gap-3"><div><h3 class="text-lg font-bold">Menunggu pengambilan</h3><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Urutan pengambilan ditentukan driver sesuai kondisi lapangan.</p></div><span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">{{ $tasks->count() }} tujuan</span></div>
            <div class="mt-4 space-y-3">
                @forelse($tasks as $task)
                    <article class="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                        <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                            <div><div class="flex flex-wrap items-center gap-2"><h4 class="font-bold">{{ $task->destination_name }}</h4>@if($task->status === 'partial')<span class="rounded-full bg-amber-100 px-2 py-1 text-[10px] font-bold text-amber-700 dark:bg-amber-500/15 dark:text-amber-300">Diambil sebagian</span>@endif</div><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Diantar {{ $task->delivery_date?->format('d/m/Y') }} · {{ $task->address ?: 'Alamat belum tersedia' }}</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Kontak: {{ $task->contact_name ?: '—' }} {{ $task->contact_phone ? '· '.$task->contact_phone : '' }}</p></div>
                            <div class="rounded-xl bg-slate-100 px-4 py-3 text-right dark:bg-slate-800"><p class="text-[10px] font-bold uppercase text-slate-500">Sisa target</p><p class="mt-1 text-xl font-bold">{{ number_format($task->remaining_containers, 0, ',', '.') }}</p><p class="text-[10px] text-slate-500">dari {{ number_format($task->target_containers, 0, ',', '.') }} ompreng</p></div>
                        </div>

                        @if($activeRun && $canOperate)
                            <div class="mt-4 grid gap-3 lg:grid-cols-[180px_1fr_1fr_auto_auto]">
                                <input wire:model="partialQuantities.{{ $task->id }}" type="number" min="1" max="{{ $task->remaining_containers }}" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="Jumlah sebagian">
                                <input wire:model="partialNotes.{{ $task->id }}" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="Alasan sisa belum diambil">
                                <input wire:model="collectionPhotos.{{ $task->id }}" type="file" accept="image/*" class="block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs dark:border-slate-700 dark:bg-slate-950">
                                <button type="button" wire:click="collectPartial({{ $task->id }})" class="h-10 rounded-xl border border-amber-300 px-4 text-xs font-bold text-amber-700 dark:border-amber-500/40 dark:text-amber-300">Diambil sebagian</button>
                                <button type="button" wire:click="collectAll({{ $task->id }})" wire:confirm="Tandai seluruh ompreng tujuan ini sudah diambil?" class="h-10 rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white hover:bg-emerald-700">Ompreng sudah diambil</button>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="rounded-xl border border-dashed border-slate-300 p-10 text-center text-sm text-slate-500 dark:border-slate-700">Tidak ada ompreng yang menunggu diambil.</div>
                @endforelse
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
            <h3 class="font-bold">Riwayat kegiatan pengambilan</h3>
            <div class="mt-4 overflow-x-auto"><table class="min-w-full text-sm"><thead><tr class="border-b text-left text-[10px] font-bold uppercase tracking-[.12em] text-slate-500"><th class="py-3 pr-4">Nomor</th><th class="py-3 pr-4">Driver</th><th class="py-3 pr-4">Tanggal</th><th class="py-3 pr-4">Tujuan</th><th class="py-3 pr-4">Ompreng</th><th class="py-3">Status</th></tr></thead><tbody>@forelse($recentRuns as $run)<tr class="border-b border-slate-100 dark:border-slate-800"><td class="py-3 pr-4 font-bold">{{ $run->run_number }}</td><td class="py-3 pr-4">{{ $run->driver_name_snapshot }}</td><td class="py-3 pr-4">{{ $run->collection_date?->format('d/m/Y') }}</td><td class="py-3 pr-4">{{ $run->items_count }}</td><td class="py-3 pr-4">{{ number_format($run->total_collected, 0, ',', '.') }}</td><td class="py-3"><span class="font-bold {{ $run->state === 'returned' ? 'text-emerald-600' : 'text-sky-600' }}">{{ $run->state === 'returned' ? 'Kembali ke SPPG' : 'Aktif' }}</span></td></tr>@empty<tr><td colspan="6" class="py-8 text-center text-slate-500">Belum ada riwayat pengambilan.</td></tr>@endforelse</tbody></table></div>
        </section>
    </div>
</x-v3.shell>
