<x-v3.shell :$unit :$navigation :$roleLabel title="Rincian Kebutuhan & Pengadaan" eyebrow="Hasil otomatis">
    <div class="mx-auto max-w-[1450px] space-y-5">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div>
                <a wire:navigate href="{{ route('v3.nutrition.requirements.index') }}" class="text-xs font-bold text-sky-700">← Kembali</a>
                <h2 class="mt-3 text-2xl font-bold text-slate-950">{{ $plan->menu?->name ?? 'Menu tidak tersedia' }}</h2>
                <p class="mt-1 text-sm text-slate-500">Periode: {{ $plan->beneficiaryPeriod?->name ?? 'Data legacy' }} · {{ $plan->requirement_date?->translatedFormat('d M Y') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if (auth()->user()->is_super_admin || auth()->user()->can('nutrition.manage'))
                    <button wire:click="recalculate" wire:confirm="Hitung ulang dan sinkronkan draft pengadaan?" class="h-10 rounded-xl bg-[#081d3a] px-4 text-xs font-bold text-white">Hitung ulang & sinkronkan</button>
                @endif
                @if ($plan->procurementRequest)
                    <a wire:navigate href="{{ route('v3.procurement.show', $plan->procurementRequest) }}" class="inline-flex h-10 items-center rounded-xl bg-sky-600 px-4 text-xs font-bold text-white">Buka pengadaan</a>
                @endif
            </div>
        </div>

        @if ($actionMessage)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ $actionMessage }}</div>@endif
        @error('action')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        <section class="rounded-[26px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7">
            <div class="grid gap-3 sm:grid-cols-5">
                <div class="rounded-xl border border-white/10 bg-white/[.07] p-3"><p class="text-[10px] text-slate-400">Porsi master</p><p class="mt-1 text-xl font-bold">{{ number_format($plan->total_portions, 0, ',', '.') }}</p></div>
                <div class="rounded-xl border border-white/10 bg-white/[.07] p-3"><p class="text-[10px] text-slate-400">Porsi kecil</p><p class="mt-1 text-xl font-bold">{{ number_format(collect($plan->portion_breakdown)->where('portion_size', 'small')->sum(fn($row) => $row['master_portions'] ?? $row['actual_portions'] ?? 0), 0, ',', '.') }}</p></div>
                <div class="rounded-xl border border-white/10 bg-white/[.07] p-3"><p class="text-[10px] text-slate-400">Porsi besar</p><p class="mt-1 text-xl font-bold">{{ number_format(collect($plan->portion_breakdown)->where('portion_size', 'large')->sum(fn($row) => $row['master_portions'] ?? $row['actual_portions'] ?? 0), 0, ',', '.') }}</p></div>
                <div class="rounded-xl border border-white/10 bg-white/[.07] p-3"><p class="text-[10px] text-slate-400">Buffer</p><p class="mt-1 text-xl font-bold">{{ number_format((float) $plan->buffer_percent, 1, ',', '.') }}%</p></div>
                <div class="rounded-xl border border-cyan-300/20 bg-cyan-300/10 p-3"><p class="text-[10px] text-cyan-200/70">Total berat</p><p class="mt-1 text-xl font-bold text-cyan-100">{{ number_format((float) $plan->total_weight_kg, 2, ',', '.') }} kg</p></div>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 p-5">
                <h3 class="text-lg font-bold text-slate-950">Daftar kebutuhan pembelian</h3>
                <p class="mt-1 text-xs text-slate-500">Jumlah akhir sudah memperhitungkan BDD, susut, buffer, dan pembulatan Master Bahan.</p>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1100px] text-left">
                    <thead><tr class="border-b border-slate-100 bg-slate-50 text-[10px] font-bold uppercase tracking-wider text-slate-400"><th class="px-5 py-3.5">Bahan</th><th class="px-5 py-3.5">Hidangan</th><th class="px-5 py-3.5 text-right">Bersih</th><th class="px-5 py-3.5 text-right">BDD</th><th class="px-5 py-3.5 text-right">Susut</th><th class="px-5 py-3.5 text-right">Sebelum bulat</th><th class="px-5 py-3.5 text-right">Dipesan</th><th class="px-5 py-3.5 text-right">Estimasi</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($plan->items as $item)
                            <tr>
                                <td class="px-5 py-4"><p class="text-sm font-bold text-slate-800">{{ $item->ingredient_name_snapshot }}</p><p class="mt-1 text-xs text-slate-400">{{ $item->unit_snapshot }} · pembulatan {{ $item->rounding_increment ?: 'tanpa pembulatan' }}</p></td>
                                <td class="max-w-64 px-5 py-4 text-xs text-slate-500">{{ $item->recipe_components }}</td>
                                <td class="px-5 py-4 text-right text-sm">{{ number_format((float) $item->base_quantity, 3, ',', '.') }} {{ $item->unit_snapshot }}</td>
                                <td class="px-5 py-4 text-right text-sm">{{ number_format((float) $item->edible_portion_percent, 1, ',', '.') }}%</td>
                                <td class="px-5 py-4 text-right text-sm">× {{ number_format((float) $item->loss_factor, 2, ',', '.') }}</td>
                                <td class="px-5 py-4 text-right text-sm text-slate-500">{{ number_format((float) $item->unrounded_quantity, 3, ',', '.') }}</td>
                                <td class="px-5 py-4 text-right text-sm font-bold text-sky-800">{{ number_format((float) $item->total_quantity, 3, ',', '.') }} {{ $item->unit_snapshot }}</td>
                                <td class="px-5 py-4 text-right text-sm font-bold text-slate-700">{{ $item->estimated_total_price !== null ? 'Rp '.number_format((float) $item->estimated_total_price, 0, ',', '.') : '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-5 py-16 text-center text-sm text-slate-500">Resep belum dapat dihitung.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-v3.shell>
