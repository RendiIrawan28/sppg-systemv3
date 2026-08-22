<x-v3.shell :$unit :$navigation :$roleLabel title="Pencucian" eyebrow="Rekonsiliasi ompreng">
    <div class="mx-auto max-w-[1450px] space-y-5 text-slate-900 dark:text-slate-100">
        <x-v3.flash-alert />

        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-sky-700 dark:text-sky-300">Ruang kerja Pencucian</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">Pencucian Ompreng</h2>
                <p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">
                    Sesi muncul otomatis ketika kegiatan pengambilan ompreng kembali ke SPPG. Verifikasi jumlah, catat limbah makanan, cuci, lalu nyatakan ompreng siap digunakan.
                </p>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
                <p class="text-[10px] font-bold uppercase tracking-[.14em] text-slate-500 dark:text-slate-400">Menunggu diterima</p>
                <p class="mt-2 text-2xl font-bold text-slate-950 dark:text-white">{{ number_format($washingSummary['waiting'] ?? 0, 0, ',', '.') }}</p>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm dark:border-amber-400/30 dark:bg-amber-500/10 dark:shadow-none">
                <p class="text-[10px] font-bold uppercase tracking-[.14em] text-amber-700 dark:text-amber-300">Dalam proses</p>
                <p class="mt-2 text-2xl font-bold text-amber-900 dark:text-amber-100">{{ number_format($washingSummary['washing'] ?? 0, 0, ',', '.') }}</p>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm dark:border-emerald-400/30 dark:bg-emerald-500/10 dark:shadow-none">
                <p class="text-[10px] font-bold uppercase tracking-[.14em] text-emerald-700 dark:text-emerald-300">Siap digunakan</p>
                <p class="mt-2 text-2xl font-bold text-emerald-900 dark:text-emerald-100">{{ number_format($washingSummary['ready'] ?? 0, 0, ',', '.') }}</p>
            </div>
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
            <label class="relative block max-w-lg">
                <x-v3.icon name="search" class="pointer-events-none absolute left-3 top-3.5 size-4 text-slate-400 dark:text-slate-500" />
                <input wire:model.live.debounce.300ms="search" type="search" placeholder="Cari nomor sesi, nomor pengambilan, atau driver..." class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-sky-400 focus:ring-2 focus:ring-sky-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-sky-400 dark:focus:ring-sky-500/20">
            </label>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm dark:divide-slate-700">
                    <thead class="bg-slate-50 dark:bg-slate-950/70">
                        <tr>
                            <th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500 dark:text-slate-400">Sesi / rute</th>
                            <th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500 dark:text-slate-400">Tanggal</th>
                            <th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500 dark:text-slate-400">Ompreng</th>
                            <th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500 dark:text-slate-400">Limbah</th>
                            <th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500 dark:text-slate-400">Status</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                        @forelse ($records as $record)
                            @php
                                $wasteLabel = $record->has_food_waste === true
                                    ? 'Ada limbah'
                                    : ($record->has_food_waste === false && $record->no_waste_confirmed ? 'Tidak ada limbah' : 'Belum dicatat');
                                $stateClass = match ($record->state) {
                                    App\Enums\WashingSessionState::Ready => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
                                    App\Enums\WashingSessionState::Washing => 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
                                    App\Enums\WashingSessionState::Received => 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
                                    default => 'bg-slate-100 text-slate-700 dark:bg-slate-700 dark:text-slate-200',
                                };
                            @endphp
                            <tr class="transition hover:bg-slate-50/70 dark:hover:bg-slate-800/70">
                                <td class="px-5 py-4">
                                    <p class="font-bold text-slate-900 dark:text-slate-100">{{ $record->containerCollectionRun?->run_number ?: $record->distributionRun?->route_name ?: 'Pengambilan Ompreng' }}</p>
                                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ $record->session_number }}</p>
                                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Driver: {{ $record->containerCollectionRun?->driver_name_snapshot ?: $record->distributionRun?->driver_name ?: '—' }}</p>
                                </td>
                                <td class="px-5 py-4 text-slate-600 dark:text-slate-300">{{ $record->washing_date?->translatedFormat('d M Y') ?? '—' }}</td>
                                <td class="px-5 py-4">
                                    <p class="font-semibold text-slate-700 dark:text-slate-200">Diterima {{ number_format($record->received_containers, 0, ',', '.') }}</p>
                                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Bersih {{ number_format($record->clean_containers, 0, ',', '.') }} · Rusak {{ number_format($record->damaged_containers, 0, ',', '.') }}</p>
                                </td>
                                <td class="px-5 py-4">
                                    <p class="font-semibold text-slate-700 dark:text-slate-200">{{ $wasteLabel }}</p>
                                    @if ($record->has_food_waste)
                                        <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">{{ $record->wasteRecords()->count() }} catatan</p>
                                    @endif
                                </td>
                                <td class="px-5 py-4">
                                    <span class="rounded-full px-2.5 py-1 text-[10px] font-bold {{ $stateClass }}">{{ $record->state?->label() }}</span>
                                    <p class="mt-2 text-[10px] font-semibold text-slate-400 dark:text-slate-500">{{ $record->status?->label() }}</p>
                                </td>
                                <td class="px-5 py-4 text-right">
                                    <a wire:navigate href="{{ route('v3.operations.show', ['module' => 'pencucian', 'record' => $record]) }}" class="inline-flex h-9 items-center rounded-xl bg-sky-600 px-3 text-xs font-bold text-white transition hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-sky-400/30 dark:bg-sky-500 dark:text-slate-950 dark:hover:bg-sky-400">
                                        Buka sesi
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-14 text-center">
                                    <p class="font-semibold text-slate-600 dark:text-slate-300">Belum ada sesi Pencucian.</p>
                                    <p class="mt-1 text-xs text-slate-400 dark:text-slate-500">Sesi akan muncul otomatis setelah kegiatan pengambilan ompreng kembali ke SPPG.</p>
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
