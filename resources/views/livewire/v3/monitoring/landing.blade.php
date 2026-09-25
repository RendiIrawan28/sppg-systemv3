<x-v3.monitoring-shell :$unit :$navigation :$roleLabel title="Beranda Monitoring">
    @php
        $toneClasses = [
            'sky' => 'bg-sky-50 text-sky-700 ring-sky-100 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/20',
            'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20',
            'amber' => 'bg-amber-50 text-amber-700 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20',
            'rose' => 'bg-rose-50 text-rose-700 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/20',
            'violet' => 'bg-violet-50 text-violet-700 ring-violet-100 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/20',
            'slate' => 'bg-slate-100 text-slate-600 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700',
        ];
        $monitoringModules = [
            ['tab' => 'overview', 'label' => 'Ikhtisar', 'icon' => 'check-badge', 'description' => 'Ringkasan KPI dan progres seluruh divisi.', 'stage' => null, 'summary' => null],
            ['tab' => 'warehouse', 'label' => 'Gudang', 'icon' => 'box', 'description' => 'Penerimaan, pengambilan, retur, dan perhatian stok.', 'stage' => 'Gudang', 'summary' => null],
            ['tab' => 'preparation', 'label' => 'Persiapan', 'icon' => 'clipboard', 'description' => 'Bahan diterima, hasil bersih, limbah, dan dokumentasi.', 'stage' => 'Persiapan', 'summary' => null],
            ['tab' => 'processing', 'label' => 'Pengolahan', 'icon' => 'nutrition', 'description' => 'Batch, bahan aktual, suhu matang, dan hasil produksi.', 'stage' => 'Pengolahan', 'summary' => null],
            ['tab' => 'portioning', 'label' => 'Pemorsian', 'icon' => 'calculator', 'description' => 'Target, hasil pemorsian, rute, dan sisa makanan.', 'stage' => 'Pemorsian', 'summary' => 'portioning'],
            ['tab' => 'distribution', 'label' => 'Distribusi', 'icon' => 'truck', 'description' => 'Rute, tujuan, jumlah porsi, dan waktu distribusi.', 'stage' => 'Distribusi', 'summary' => 'distribution'],
        ];
    @endphp

    @php
        foreach (\App\Support\V3\MonitoringNavigation::FINAL_MODULES as $key => $definition) {
            $monitoringModules[] = [...$definition, 'tab' => $key, 'stage' => $definition['label'], 'summary' => $key];
        }
    @endphp

    <div class="mx-auto max-w-[1550px] space-y-6">
        <section class="relative overflow-hidden rounded-[28px] bg-[#081d3a] p-6 text-white shadow-xl shadow-slate-900/10 sm:p-7">
            <div class="absolute inset-0 opacity-30 [background-image:radial-gradient(circle_at_88%_12%,#22d3ee_0,transparent_24%),radial-gradient(circle_at_72%_125%,#84cc16_0,transparent_29%)]"></div>
            <div class="relative flex flex-col gap-6 xl:flex-row xl:items-end xl:justify-between">
                <div class="max-w-2xl">
                    <p class="text-[10px] font-bold uppercase tracking-[.2em] text-cyan-200">Monitoring Operasional</p>
                    <h2 class="mt-3 text-3xl font-bold tracking-[-.035em] sm:text-4xl">Pantau seluruh proses layanan SPPG.</h2>
                    <p class="mt-3 text-sm text-slate-300 sm:text-base">{{ $unit->name }} · {{ \Carbon\Carbon::parse($workDate)->translatedFormat('l, d F Y') }}</p>
                    <a href="{{ route('v3.monitoring.operational', ['tab' => 'overview', 'date' => $workDate]) }}" wire:navigate class="mt-5 inline-flex h-11 items-center gap-2 rounded-xl bg-cyan-300 px-5 text-xs font-bold text-[#071a34] transition hover:bg-cyan-200">Buka Ikhtisar<x-v3.icon name="arrow-up-right" class="size-4" /></a>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/[.07] p-3 backdrop-blur-sm">
                    <p class="px-1 text-[10px] font-bold uppercase tracking-[.16em] text-slate-400">Tanggal monitoring</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <button wire:click="previousDay" type="button" class="grid size-10 place-items-center rounded-xl border border-white/10 bg-white/[.07] text-lg font-bold transition hover:bg-white/[.13]" aria-label="Tanggal sebelumnya">‹</button>
                        <input wire:model="workDate" wire:change="refreshData" type="date" class="h-10 min-w-40 rounded-xl border border-white/10 bg-white px-3 text-sm font-bold text-slate-800 outline-none focus:border-cyan-300 focus:ring-2 focus:ring-cyan-300/20">
                        <button wire:click="nextDay" type="button" class="grid size-10 place-items-center rounded-xl border border-white/10 bg-white/[.07] text-lg font-bold transition hover:bg-white/[.13]" aria-label="Tanggal berikutnya">›</button>
                        <button wire:click="useToday" type="button" class="h-10 rounded-xl bg-white/[.1] px-4 text-xs font-bold transition hover:bg-white/[.16]">Hari ini</button>
                        <button wire:click="refreshData" type="button" class="inline-flex h-10 items-center gap-2 rounded-xl border border-white/10 bg-white/[.07] px-4 text-xs font-bold transition hover:bg-white/[.13]"><span wire:loading.remove wire:target="refreshData">↻</span><span wire:loading wire:target="refreshData" class="size-3.5 animate-spin rounded-full border-2 border-white/30 border-t-white"></span>Refresh</button>
                    </div>
                    <p class="mt-2 px-1 text-[10px] text-slate-400">{{ $lastRefreshedAt ? 'Terakhir diperbarui '.$lastRefreshedAt : 'Belum berhasil dimuat' }}</p>
                </div>
            </div>
        </section>

        @if($loadError)
            <div role="alert" class="rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200">Ringkasan monitoring belum dapat dimuat. Pilih tanggal atau tekan Refresh untuk mencoba lagi; waktu pembaruan terakhir tidak berubah.</div>
        @else
        <section>
            <div class="mb-4"><p class="text-[10px] font-bold uppercase tracking-[.18em] text-sky-700 dark:text-sky-300">Snapshot layanan</p><h3 class="mt-1 text-xl font-bold text-slate-950 dark:text-slate-50">Kondisi pada tanggal terpilih</h3></div>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($overview['cards'] as $card)
                    <article class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950">
                        <div class="flex items-start justify-between gap-3"><div class="min-w-0"><p class="text-xs font-semibold text-slate-500 dark:text-slate-400">{{ $card['label'] }}</p><p class="mt-2 truncate text-3xl font-bold tracking-[-.04em] text-slate-950 dark:text-slate-50">{{ is_numeric($card['value']) ? number_format($card['value'], 0, ',', '.') : $card['value'] }}</p></div><span class="grid size-10 shrink-0 place-items-center rounded-xl ring-1 {{ $toneClasses[$card['tone']] ?? $toneClasses['slate'] }}"><x-v3.icon :name="$card['icon']" class="size-5" /></span></div>
                        <p class="mt-3 text-xs leading-5 text-slate-500 dark:text-slate-400">{{ $card['detail'] }}</p>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-[.9fr_1.1fr]">
            <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950 sm:p-6">
                <p class="text-[10px] font-bold uppercase tracking-[.18em] text-sky-700 dark:text-sky-300">Pilih monitoring</p>
                <h3 class="mt-1 text-xl font-bold text-slate-950 dark:text-slate-50">Buka area yang ingin dipantau</h3>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    @foreach ($monitoringModules as $module)
                        @php($stage = $module['stage'] ? collect($progress)->firstWhere('label', $module['stage']) : null)
                        <a href="{{ route('v3.monitoring.operational', ['tab' => $module['tab'], 'date' => $workDate]) }}" wire:navigate class="group rounded-2xl border border-slate-200 bg-slate-50/70 p-4 transition hover:-translate-y-0.5 hover:border-sky-300 hover:bg-sky-50/60 dark:border-slate-800 dark:bg-slate-900/70 dark:hover:border-sky-500/40 dark:hover:bg-sky-500/5">
                            <div class="flex items-start justify-between gap-3"><span class="grid size-10 place-items-center rounded-xl bg-white text-sky-700 ring-1 ring-slate-200 dark:bg-slate-950 dark:text-sky-300 dark:ring-slate-700"><x-v3.icon :name="$module['icon']" class="size-5" /></span><x-v3.icon name="arrow-up-right" class="size-4 text-slate-400 transition group-hover:text-sky-600" /></div>
                            <p class="mt-4 text-sm font-bold text-slate-900 dark:text-slate-100">{{ $module['label'] }}</p><p class="mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400">{{ $module['description'] }}</p>
                            @if ($module['summary'])<p class="mt-2 text-[11px] font-bold text-slate-700 dark:text-slate-300">{{ $moduleSummaries[$module['summary']]['primary'] }} · {{ $moduleSummaries[$module['summary']]['secondary'] }}</p>@endif
                            @if ($stage)<span class="mt-3 inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold ring-1 {{ $toneClasses[$stage['tone']] ?? $toneClasses['slate'] }}">{{ $stage['status'] }}</span>@endif
                        </a>
                    @endforeach
                </div>
            </div>

            <div class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-950 sm:p-6">
                <p class="text-[10px] font-bold uppercase tracking-[.18em] text-sky-700 dark:text-sky-300">Progres operasional</p><h3 class="mt-1 text-xl font-bold text-slate-950 dark:text-slate-50">Status lintas divisi</h3>
                <div class="mt-5 grid gap-3 sm:grid-cols-2">
                    @foreach ($progress as $stage)
                        <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50/70 p-3 dark:border-slate-800 dark:bg-slate-900/70"><span class="grid size-9 shrink-0 place-items-center rounded-xl bg-white text-slate-600 ring-1 ring-slate-200 dark:bg-slate-950 dark:text-slate-300 dark:ring-slate-700"><x-v3.icon :name="$stage['icon']" class="size-4.5" /></span><div class="min-w-0 flex-1"><p class="truncate text-xs font-bold text-slate-800 dark:text-slate-200">{{ $stage['label'] }}</p><p class="mt-0.5 text-[10px] text-slate-400">{{ $stage['total'] }} data</p></div><span class="shrink-0 rounded-full px-2 py-1 text-[9px] font-bold ring-1 {{ $toneClasses[$stage['tone']] ?? $toneClasses['slate'] }}">{{ $stage['status'] }}</span></div>
                    @endforeach
                </div>
            </div>
        </section>

        @endif
        <div wire:loading.flex wire:target="workDate,previousDay,nextDay,useToday,refreshData" class="fixed inset-0 z-[90] items-center justify-center bg-slate-950/10 backdrop-blur-[1px] dark:bg-black/30"><div class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-white px-5 py-4 text-sm font-bold text-slate-700 shadow-xl dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200"><span class="size-5 animate-spin rounded-full border-2 border-slate-200 border-t-sky-600 dark:border-slate-700 dark:border-t-sky-400"></span>Memuat monitoring…</div></div>
    </div>
</x-v3.monitoring-shell>
