<x-v3.shell :$unit :$navigation :$roleLabel title="Monitoring Operasional" eyebrow="Monitoring lintas divisi">
    @php
        $toneClasses = [
            'sky' => 'bg-sky-50 text-sky-700 ring-sky-100 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/20',
            'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20',
            'amber' => 'bg-amber-50 text-amber-700 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20',
            'rose' => 'bg-rose-50 text-rose-700 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/20',
            'violet' => 'bg-violet-50 text-violet-700 ring-violet-100 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/20',
            'slate' => 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
        ];
        $badgeClasses = [
            'sky' => 'bg-sky-50 text-sky-700 ring-sky-100 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/20',
            'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20',
            'amber' => 'bg-amber-50 text-amber-700 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20',
            'rose' => 'bg-rose-50 text-rose-700 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/20',
            'violet' => 'bg-violet-50 text-violet-700 ring-violet-100 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/20',
            'slate' => 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
        ];
    @endphp

    <div class="mx-auto max-w-[1600px] space-y-6 text-slate-900 dark:text-slate-100">
        <section class="relative overflow-hidden rounded-[28px] bg-[#081d3a] p-6 text-white shadow-xl shadow-slate-900/10 sm:p-7">
            <div class="absolute inset-0 opacity-30 [background-image:radial-gradient(circle_at_85%_15%,#22d3ee_0,transparent_24%),radial-gradient(circle_at_75%_120%,#84cc16_0,transparent_28%)]"></div>
            <div class="relative flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                <div class="max-w-3xl">
                    <div class="flex items-center gap-2 text-xs font-semibold text-cyan-200">
                        <span class="size-2 rounded-full bg-lime-300 shadow-[0_0_0_5px_rgba(190,242,100,.12)]"></span>
                        Data operasional {{ $unit->name }}
                    </div>
                    <h2 class="mt-4 text-3xl font-bold tracking-[-.035em] sm:text-4xl">Pantau kondisi operasional dari satu halaman.</h2>
                    <p class="mt-3 max-w-2xl text-sm leading-6 text-slate-300">Halaman ini bersifat baca-saja. Klik detail untuk membuka modul sumber tanpa membuat data operasional kedua.</p>
                </div>

                <div class="rounded-2xl border border-white/10 bg-white/[.07] p-3 backdrop-blur-sm">
                    <p class="px-1 text-[10px] font-bold uppercase tracking-[.16em] text-slate-400">Tanggal monitoring</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button wire:click="previousDay" type="button" class="grid size-10 place-items-center rounded-xl border border-white/10 bg-white/[.07] text-lg font-bold text-white transition hover:bg-white/[.13]" aria-label="Tanggal sebelumnya">‹</button>
                        <input wire:model="workDate" wire:change="refreshData" type="date" class="h-10 min-w-44 rounded-xl border border-white/10 bg-white px-3 text-sm font-bold text-slate-800 outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20">
                        <button wire:click="nextDay" type="button" class="grid size-10 place-items-center rounded-xl border border-white/10 bg-white/[.07] text-lg font-bold text-white transition hover:bg-white/[.13]" aria-label="Tanggal berikutnya">›</button>
                        <button wire:click="useToday" type="button" class="h-10 rounded-xl bg-cyan-300 px-4 text-xs font-bold text-[#071a34] transition hover:bg-cyan-200">Hari ini</button>
                        <button wire:click="refreshData" type="button" class="inline-flex h-10 items-center gap-2 rounded-xl border border-white/10 bg-white/[.07] px-4 text-xs font-bold text-white transition hover:bg-white/[.13]">
                            <span wire:loading.remove wire:target="refreshData">↻</span>
                            <span wire:loading wire:target="refreshData" class="size-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>
                            Refresh
                        </button>
                    </div>
                    <p class="mt-2 px-1 text-[10px] text-slate-400 dark:text-slate-500">Terakhir diperbarui {{ $lastRefreshedAt }}</p>
                </div>
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-2 rounded-2xl border border-slate-200/80 bg-white p-2 shadow-sm dark:border-slate-800 dark:bg-slate-950">
            <button
                type="button"
                wire:click="selectTab('overview')"
                @class([
                    'rounded-xl px-4 py-2.5 text-xs font-bold transition',
                    'bg-[#081d3a] text-white shadow-sm' => $activeTab === 'overview',
                    'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100' => $activeTab !== 'overview',
                ])
            >
                Ikhtisar
            </button>
            <button
                type="button"
                wire:click="selectTab('warehouse')"
                @class([
                    'rounded-xl px-4 py-2.5 text-xs font-bold transition',
                    'bg-[#081d3a] text-white shadow-sm' => $activeTab === 'warehouse',
                    'text-slate-500 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100' => $activeTab !== 'warehouse',
                ])
            >
                Gudang
            </button>
            <div class="ml-auto hidden items-center gap-2 pr-2 text-[10px] font-semibold text-slate-400 sm:flex">
                <span class="size-2 rounded-full bg-emerald-500"></span>
                Read-only monitoring
            </div>
        </div>

        @if ($activeTab === 'overview')
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($overview['cards'] as $card)
                    <article class="group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-slate-900/5 dark:border-slate-800 dark:bg-slate-950 dark:shadow-none">
                        @if ($card['url'] && (auth()->user()->is_super_admin || blank($card['permission']) || auth()->user()->can($card['permission'])))
                            <a href="{{ $card['url'] }}" wire:navigate class="absolute inset-0 z-10" aria-label="Buka detail {{ $card['label'] }}"></a>
                        @endif
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $card['label'] }}</p>
                                <p class="mt-2 truncate text-3xl font-bold tracking-[-.04em] text-slate-950 dark:text-slate-50">
                                    {{ is_numeric($card['value']) ? number_format($card['value'], 0, ',', '.') : $card['value'] }}
                                </p>
                            </div>
                            <span class="grid size-10 shrink-0 place-items-center rounded-xl ring-1 {{ $toneClasses[$card['tone']] ?? $toneClasses['slate'] }}">
                                <x-v3.icon :name="$card['icon']" class="size-5" />
                            </span>
                        </div>
                        <p class="mt-3 text-xs leading-5 text-slate-500 dark:text-slate-400">{{ $card['detail'] }}</p>
                    </article>
                @endforeach
            </section>

            <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6 dark:border-slate-800 dark:bg-slate-950 dark:shadow-none">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.17em] text-sky-700 dark:text-sky-300">Progres hari kerja</p>
                        <h3 class="mt-1 text-lg font-bold tracking-tight text-slate-950 dark:text-slate-50">Status lintas modul</h3>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Status diringkas menjadi Belum Mulai, Berjalan, Selesai, atau Diverifikasi.</p>
                    </div>
                    <p class="text-xs font-semibold text-slate-400 dark:text-slate-500">{{ \Carbon\Carbon::parse($workDate)->translatedFormat('l, d F Y') }}</p>
                </div>

                <div class="mt-6 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($progress as $stage)
                        <div class="group relative rounded-2xl border border-slate-200 bg-slate-50/70 p-4 transition hover:border-sky-200 hover:bg-sky-50/30 dark:border-slate-800 dark:bg-slate-900/70 dark:hover:border-sky-500/30 dark:hover:bg-sky-500/5">
                            @if (auth()->user()->is_super_admin || auth()->user()->can($stage['permission']))
                                <a href="{{ $stage['url'] }}" wire:navigate class="absolute inset-0 z-10 rounded-2xl" aria-label="Buka modul {{ $stage['label'] }}"></a>
                            @endif
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex min-w-0 items-center gap-3">
                                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-950 dark:text-slate-300 dark:ring-slate-700">
                                        <x-v3.icon :name="$stage['icon']" class="size-5" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="truncate text-sm font-bold text-slate-900 dark:text-slate-100">{{ $stage['label'] }}</p>
                                        <p class="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">{{ $stage['total'] }} data pada tanggal ini</p>
                                    </div>
                                </div>
                                <span class="shrink-0 rounded-full px-2.5 py-1 text-[10px] font-bold ring-1 {{ $badgeClasses[$stage['tone']] ?? $badgeClasses['slate'] }}">{{ $stage['status'] }}</span>
                            </div>
                            <div class="mt-4 h-1.5 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                                <div
                                    class="h-full rounded-full bg-sky-500 transition-all"
                                    style="width: {{ $stage['total'] > 0 ? min(100, (int) round((max($stage['completed'], $stage['verified']) / $stage['total']) * 100)) : 0 }}%"
                                ></div>
                            </div>
                            @if (! auth()->user()->is_super_admin && ! auth()->user()->can($stage['permission']))
                                <p class="mt-2 text-[10px] text-slate-400 dark:text-slate-500">Detail modul tidak tersedia untuk role ini.</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($activeTab === 'warehouse')
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($warehouse['cards'] as $card)
                    <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950 dark:shadow-none">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $card['label'] }}</p>
                                <p class="mt-2 text-3xl font-bold tracking-[-.04em] text-slate-950 dark:text-slate-50">{{ is_numeric($card['value']) ? number_format($card['value'], 0, ',', '.') : $card['value'] }}</p>
                            </div>
                            <span class="grid size-10 place-items-center rounded-xl ring-1 {{ $toneClasses[$card['tone']] ?? $toneClasses['slate'] }}">
                                <x-v3.icon :name="$card['icon']" class="size-5" />
                            </span>
                        </div>
                        <p class="mt-3 text-xs leading-5 text-slate-500 dark:text-slate-400">{{ $card['detail'] }}</p>
                    </article>
                @endforeach
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-950 dark:shadow-none">
                <div class="flex flex-col gap-3 border-b border-slate-200 dark:border-slate-800 px-5 py-5 sm:flex-row sm:items-end sm:justify-between sm:px-6">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.17em] text-sky-700 dark:text-sky-300">Gudang</p>
                        <h3 class="mt-1 text-lg font-bold text-slate-950 dark:text-slate-50">Arus bahan pada tanggal terpilih</h3>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Penerimaan, pengambilan oleh divisi, dan retur ditampilkan dalam satu tabel.</p>
                    </div>
                    @if (auth()->user()->is_super_admin || auth()->user()->can('stock.view'))
                        <a href="{{ route('v3.warehouse.withdrawals.index') }}" wire:navigate class="inline-flex h-10 items-center gap-2 self-start rounded-xl bg-slate-100 px-4 text-xs font-bold text-slate-700 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700 sm:self-auto">
                            Buka modul Gudang
                            <x-v3.icon name="arrow-up-right" class="size-4" />
                        </a>
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-[1180px] w-full text-left">
                        <thead class="bg-slate-50 text-[10px] font-bold uppercase tracking-[.12em] text-slate-500 dark:bg-slate-900 dark:text-slate-400">
                            <tr>
                                <th class="px-5 py-3">Aktivitas</th>
                                <th class="px-4 py-3">Bahan</th>
                                <th class="px-4 py-3 text-right">Diterima</th>
                                <th class="px-4 py-3 text-right">Keluar</th>
                                <th class="px-4 py-3">Divisi</th>
                                <th class="px-4 py-3">Petugas</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Waktu</th>
                                <th class="px-5 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 text-sm dark:divide-slate-800">
                            @forelse ($warehouse['rows'] as $row)
                                <tr class="transition hover:bg-slate-50/70 dark:hover:bg-slate-900/70">
                                    <td class="px-5 py-4 align-top">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold ring-1 {{ $badgeClasses[$row['type_tone']] ?? $badgeClasses['slate'] }}">{{ $row['type'] }}</span>
                                        <p class="mt-1.5 text-[10px] text-slate-400 dark:text-slate-500">{{ $row['reference'] }}</p>
                                    </td>
                                    <td class="px-4 py-4 align-top">
                                        <p class="max-w-64 font-bold text-slate-900 dark:text-slate-100">{{ $row['ingredient'] }}</p>
                                        <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">Satuan: {{ $row['unit'] }}</p>
                                    </td>
                                    <td class="px-4 py-4 text-right align-top font-bold text-emerald-700 dark:text-emerald-300">
                                        {{ $row['received'] > 0 ? number_format($row['received'], 3, ',', '.').' '.$row['unit'] : '—' }}
                                    </td>
                                    <td class="px-4 py-4 text-right align-top font-bold text-amber-700 dark:text-amber-300">
                                        {{ $row['outgoing'] > 0 ? number_format($row['outgoing'], 3, ',', '.').' '.$row['unit'] : '—' }}
                                    </td>
                                    <td class="px-4 py-4 align-top text-xs font-semibold text-slate-700 dark:text-slate-300">{{ $row['division'] }}</td>
                                    <td class="px-4 py-4 align-top text-xs text-slate-600 dark:text-slate-300">{{ $row['officer'] }}</td>
                                    <td class="px-4 py-4 align-top">
                                        <span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold ring-1 {{ $badgeClasses[$row['status_tone']] ?? $badgeClasses['slate'] }}">{{ $row['status'] }}</span>
                                    </td>
                                    <td class="px-4 py-4 align-top text-xs font-semibold text-slate-600 dark:text-slate-300">{{ $row['time'] }}</td>
                                    <td class="px-5 py-4 align-top">
                                        <div class="flex justify-end gap-2">
                                            @if ($row['photo_url'])
                                                <x-v3.documentation-button :url="$row['photo_url']" :title="$row['type'].' · '.$row['ingredient']" label="Foto" class="!px-2.5 !py-1.5" />
                                            @endif
                                            @if (auth()->user()->is_super_admin || auth()->user()->can('stock.view'))
                                                <a href="{{ $row['detail_url'] }}" wire:navigate class="inline-flex items-center rounded-xl bg-slate-100 px-3 py-1.5 text-[11px] font-bold text-slate-700 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700">Detail</a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-6 py-14 text-center">
                                        <div class="mx-auto grid size-12 place-items-center rounded-2xl bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500">
                                            <x-v3.icon name="box" class="size-6" />
                                        </div>
                                        <p class="mt-3 text-sm font-bold text-slate-700 dark:text-slate-200">Belum ada aktivitas Gudang</p>
                                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Tidak ditemukan penerimaan, pengambilan, atau retur pada {{ \Carbon\Carbon::parse($workDate)->translatedFormat('d F Y') }}.</p>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if (count($warehouse['rows']) >= 200)
                    <div class="border-t border-slate-100 bg-slate-50 dark:border-slate-800 dark:bg-slate-900 px-5 py-3 text-center text-[11px] font-semibold text-slate-500 dark:text-slate-400">Menampilkan maksimal 200 baris terbaru agar halaman monitoring tetap ringan.</div>
                @endif
            </section>
        @endif

        <div wire:loading.flex wire:target="workDate,previousDay,nextDay,useToday,refreshData,selectTab" class="fixed inset-0 z-[90] items-center justify-center bg-slate-950/10 backdrop-blur-[1px] dark:bg-black/30">
            <div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4 text-sm font-bold text-slate-700 shadow-xl dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
                <span class="size-5 animate-spin rounded-full border-2 border-slate-200 border-t-sky-600 dark:border-slate-700 dark:border-t-sky-400"></span>
                Memuat monitoring…
            </div>
        </div>
    </div>
</x-v3.shell>
