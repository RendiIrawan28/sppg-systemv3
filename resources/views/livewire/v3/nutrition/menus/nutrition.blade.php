<x-v3.shell :$unit :$navigation :$roleLabel title="Hasil Kebutuhan Gizi Harian" eyebrow="Evaluasi asupan satu kali makan">
    <div class="mx-auto max-w-[1450px] space-y-5">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
            <div>
                <a wire:navigate href="{{ route('v3.nutrition.menus.show', $menu) }}" class="text-xs font-bold text-sky-700">← Kembali ke editor resep</a>
                <h2 class="mt-3 text-2xl font-bold text-slate-950">{{ $menu->name }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $menu->code }} · {{ $menu->status->label() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a wire:navigate href="{{ route('v3.nutrition.menu-matrix') }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-600">Kembali ke matriks</a>
                @if($canRecalculate)
                    <button type="button" wire:click="recalculate" wire:loading.attr="disabled" class="h-10 rounded-xl bg-sky-600 px-4 text-xs font-bold text-white disabled:opacity-60">
                        <span wire:loading.remove wire:target="recalculate">Hitung ulang gizi</span>
                        <span wire:loading wire:target="recalculate">Menghitung...</span>
                    </button>
                @endif
            </div>
        </div>

        @if (session('v3.status'))<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('v3.status') }}</div>@endif
        @if ($actionMessage)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ $actionMessage }}</div>@endif
        @error('action')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        @php
            $summaries = $menu->nutritionSummaries->sortBy(fn($row) => sprintf('%04d-%04d', $row->category?->sort_order ?? 9999, $row->component?->sort_order ?? 9999));
            $withinRange = $summaries->filter(fn($row) => $row->achievement_percent !== null && (float) $row->achievement_percent >= 25 && (float) $row->achievement_percent <= 35)->count();
            $needsReview = $summaries->count() - $withinRange;
        @endphp

        <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Porsi rencana</p><strong class="mt-2 block text-2xl text-slate-950">{{ number_format($menu->planned_portions, 0, ',', '.') }}</strong></div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Kelompok penerima</p><strong class="mt-2 block text-2xl text-slate-950">{{ $menu->categoryTargets->count() }}</strong></div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm"><p class="text-[10px] font-bold uppercase tracking-wider text-emerald-700">Dalam rentang 25–35%</p><strong class="mt-2 block text-2xl text-emerald-800">{{ $withinRange }}</strong></div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm"><p class="text-[10px] font-bold uppercase tracking-wider text-amber-700">Perlu diperiksa</p><strong class="mt-2 block text-2xl text-amber-800">{{ $needsReview }}</strong></div>
        </section>

        @if ($summaries->isNotEmpty())
            <section class="overflow-hidden rounded-[26px] bg-[#081d3a] text-white shadow-xl">
                <div class="p-5">
                    <p class="text-[10px] font-bold uppercase tracking-[.18em] text-cyan-300">Kebutuhan gizi harian</p>
                    <h3 class="mt-1 text-lg font-bold">Kontribusi menu terhadap kebutuhan satu hari penuh</h3>
                    <p class="mt-1 text-xs text-slate-400">Asupan satu porsi dibandingkan dengan kebutuhan satu hari penuh (100% AKG) pada setiap kelompok penerima.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full border-collapse text-xs">
                        <thead class="bg-white/[.06] text-left text-[10px] uppercase tracking-wider text-slate-300">
                            <tr><th class="px-4 py-3">Kelompok</th><th class="px-4 py-3">Komponen gizi</th><th class="px-4 py-3 text-right">Asupan/menu</th><th class="px-4 py-3 text-right">Kebutuhan harian</th><th class="px-4 py-3 text-right">Kontribusi</th><th class="px-4 py-3">Status</th></tr>
                        </thead>
                        <tbody class="divide-y divide-white/10">
                            @foreach($summaries as $summary)
                                @php($ok = $summary->achievement_percent !== null && (float)$summary->achievement_percent >= 25 && (float)$summary->achievement_percent <= 35)
                                <tr>
                                    <td class="px-4 py-2 font-semibold text-white">{{ $summary->category?->name ?? '-' }}</td>
                                    <td class="px-4 py-2 text-slate-300">{{ $summary->component?->name ?? '-' }}</td>
                                    <td class="px-4 py-2 text-right font-semibold">{{ number_format((float)$summary->value_per_portion, 2, ',', '.') }} {{ $summary->component?->unit }}</td>
                                    <td class="px-4 py-2 text-right text-slate-300">{{ $summary->standard_target !== null ? number_format((float)$summary->standard_target, 2, ',', '.') : '-' }}</td>
                                    <td class="px-4 py-2 text-right font-bold {{ $ok ? 'text-emerald-300' : 'text-amber-300' }}">{{ $summary->achievement_percent !== null ? number_format((float)$summary->achievement_percent, 1, ',', '.').'%' : '-' }}</td>
                                    <td class="px-4 py-2"><span class="rounded-full px-2 py-1 text-[9px] font-bold {{ $ok ? 'bg-emerald-400/15 text-emerald-300' : 'bg-amber-400/15 text-amber-300' }}">{{ $ok ? '25–35%' : 'Periksa' }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @else
            <section class="rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center">
                <h3 class="font-bold text-slate-900">Hasil nilai gizi belum tersedia</h3>
                <p class="mt-1 text-sm text-slate-500">Lengkapi bahan dan gramasi resep, kemudian lakukan perhitungan nilai gizi.</p>
                @if($canRecalculate)<button type="button" wire:click="recalculate" class="mt-4 h-10 rounded-xl bg-sky-600 px-4 text-xs font-bold text-white">Hitung nilai gizi</button>@endif
            </section>
        @endif
    </div>
</x-v3.shell>
