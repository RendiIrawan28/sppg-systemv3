<x-v3.shell :$unit :$navigation :$roleLabel title="Distribusi" eyebrow="Pelaksanaan rute">
    <div class="mx-auto max-w-[1450px] space-y-5 text-slate-900 dark:text-slate-100">
        <x-v3.flash-alert />

        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-sky-700 dark:text-sky-300">Ruang kerja driver</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">Rute Distribusi</h2>
                <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-slate-400">
                    Pilih satu rute, lakukan pengantaran, kembali ke SPPG, kemudian pilih rute berikutnya.
                </p>
            </div>
            <div class="rounded-2xl border border-sky-100 bg-sky-50 px-5 py-3 text-right dark:border-sky-400/25 dark:bg-sky-500/10">
                <p class="text-[10px] font-bold uppercase tracking-[.14em] text-sky-600 dark:text-sky-300">Rute tersedia</p>
                <p class="mt-1 text-2xl font-bold text-sky-900 dark:text-sky-100">{{ number_format($availableCount ?? 0, 0, ',', '.') }}</p>
            </div>
        </div>

        <x-v3.date-filter label="Tanggal distribusi" />
        <x-v3.pending-work :records="$attentionRecords" module="distribusi" :definition="$definition" />

        @if ($activeRoute)
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm dark:border-amber-400/30 dark:bg-amber-500/10 dark:shadow-none">
                <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.14em] text-amber-700 dark:text-amber-300">Rute aktif saya</p>
                        <h3 class="mt-2 text-lg font-bold text-slate-900 dark:text-white">{{ $activeRoute->route_name }}</h3>
                        <p class="mt-1 text-xs text-slate-600 dark:text-slate-300">
                            {{ $activeRoute->state?->label() }} · {{ $activeRoute->stops()->count() }} tujuan · {{ number_format($activeRoute->planned_total, 0, ',', '.') }} porsi
                        </p>
                    </div>
                    <a wire:navigate href="{{ route('v3.operations.show', ['module' => 'distribusi', 'record' => $activeRoute]) }}" class="inline-flex h-10 items-center justify-center rounded-xl bg-amber-600 px-4 text-xs font-bold text-white transition hover:bg-amber-700 focus:outline-none focus:ring-2 focus:ring-amber-400/40 dark:bg-amber-500 dark:text-slate-950 dark:hover:bg-amber-400">
                        Lanjutkan rute
                    </a>
                </div>
            </section>
        @endif

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
            <label class="relative block max-w-lg">
                <x-v3.icon name="search" class="pointer-events-none absolute left-3 top-3.5 size-4 text-slate-400 dark:text-slate-500" />
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Cari nomor, rute, atau driver..." class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-sky-400 focus:ring-2 focus:ring-sky-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-sky-400 dark:focus:ring-sky-500/20">
            </label>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 dark:bg-slate-950/70">
                        <tr>
                            <th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500 dark:text-slate-400">Rute</th>
                            <th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500 dark:text-slate-400">Tanggal</th>
                            <th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500 dark:text-slate-400">Tujuan & porsi</th>
                            <th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500 dark:text-slate-400">Driver</th>
                            <th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500 dark:text-slate-400">Status</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        @forelse ($records as $record)
                            @php
                                $available = $record->state === App\Enums\DistributionRunState::Planned;
                                $mine = (int) $record->petugas_id === (int) auth()->id();
                            @endphp
                            <tr class="transition hover:bg-slate-50/70 dark:hover:bg-slate-800/70">
                                <td class="px-5 py-4">
                                    <p class="font-bold text-slate-900 dark:text-slate-100">{{ $record->route_name ?: 'Rute Utama' }}</p>
                                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ $record->run_number }}</p>
                                </td>
                                <td class="px-5 py-4 text-slate-600 dark:text-slate-300">{{ $record->distribution_date?->translatedFormat('d M Y') ?? '—' }}</td>
                                <td class="px-5 py-4">
                                    <p class="font-semibold text-slate-700 dark:text-slate-200">{{ $record->stops()->count() }} tujuan</p>
                                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ number_format($record->planned_total, 0, ',', '.') }} porsi</p>
                                </td>
                                <td class="px-5 py-4">
                                    <p class="font-semibold text-slate-700 dark:text-slate-200">{{ $record->driver_name ?: 'Belum dipilih' }}</p>
                                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ $record->vehicle_plate ?: '—' }}</p>
                                </td>
                                <td class="px-5 py-4">
                                    <span class="rounded-full px-2.5 py-1 text-[10px] font-bold {{ $available ? 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200' : ($record->state === App\Enums\DistributionRunState::Returned ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300') }}">
                                        {{ $record->state?->label() }}
                                    </span>
                                    @if ($mine && !$available)
                                        <p class="mt-2 text-[10px] font-bold text-sky-700 dark:text-sky-300">Rute Anda</p>
                                    @endif
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <a wire:navigate href="{{ route('v3.operations.show', ['module' => 'distribusi', 'record' => $record]) }}" class="inline-flex h-9 items-center rounded-xl px-3 text-xs font-bold transition focus:outline-none focus:ring-2 focus:ring-sky-400/30 {{ $available ? 'bg-sky-600 text-white hover:bg-sky-700 dark:bg-sky-500 dark:text-slate-950 dark:hover:bg-sky-400' : 'text-sky-700 hover:bg-sky-50 dark:text-sky-300 dark:hover:bg-sky-500/10' }}">
                                        {{ $available ? 'Pilih / lihat rute' : 'Buka rincian' }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-14 text-center">
                                    <p class="font-semibold text-slate-600 dark:text-slate-300">Belum ada rute distribusi.</p>
                                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Rute muncul otomatis setelah rencana Asisten Lapangan diaktifkan.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($records->hasPages())
                <div class="border-t border-slate-100 p-4 dark:border-slate-700">{{ $records->links() }}</div>
            @endif
        </section>
    </div>
</x-v3.shell>
