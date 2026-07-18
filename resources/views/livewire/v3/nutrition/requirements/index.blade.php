<x-v3.shell :$unit :$navigation :$roleLabel title="Kebutuhan & Pengadaan" eyebrow="Otomatis dari porsi aktual">
    <div class="mx-auto max-w-[1450px] space-y-5">
        @if (session('v3.status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">
                {{ session('v3.status') }}
            </div>
        @endif

        <section class="rounded-[28px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7">
            <div class="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                <div>
                    <span class="rounded-full bg-cyan-300/10 px-3 py-1 text-[10px] font-bold uppercase tracking-[.16em] text-cyan-200 ring-1 ring-cyan-300/20">Alur spreadsheet</span>
                    <h2 class="mt-4 text-2xl font-bold sm:text-3xl">Kebutuhan dihitung dari rencana distribusi.</h2>
                    <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-300">Ahli Gizi tidak perlu membuat rencana manual. Sistem menggunakan porsi aktual, resep, BDD, susut, buffer, satuan pembelian, dan aturan pembulatan pada Master Bahan.</p>
                </div>
                <a wire:navigate href="{{ route('v3.field.plans.index') }}" class="inline-flex h-11 items-center rounded-xl border border-white/15 bg-white/10 px-4 text-xs font-bold text-white">Buka rencana distribusi</a>
            </div>
            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-white/10 bg-white/[.07] px-4 py-3"><p class="text-[10px] uppercase text-slate-400">Perhitungan</p><p class="mt-1 text-xl font-bold">{{ number_format($planCount, 0, ',', '.') }}</p></div>
                <div class="rounded-2xl border border-cyan-300/20 bg-cyan-300/10 px-4 py-3"><p class="text-[10px] uppercase text-cyan-200/70">Total berat</p><p class="mt-1 text-xl font-bold text-cyan-100">{{ number_format($totalWeight, 2, ',', '.') }} kg</p></div>
                <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3"><p class="text-[10px] uppercase text-emerald-200/70">Draft pengadaan</p><p class="mt-1 text-xl font-bold text-emerald-100">{{ number_format($readyCount, 0, ',', '.') }}</p></div>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 p-4 sm:p-5">
                <input wire:model.live.debounce.350ms="search" type="search" placeholder="Cari nomor rencana atau menu..." class="h-11 w-full max-w-xl rounded-xl border border-slate-200 bg-slate-50 px-4 text-sm">
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[950px] text-left">
                    <thead><tr class="border-b border-slate-100 bg-slate-50/70 text-[10px] font-bold uppercase tracking-wider text-slate-400"><th class="px-5 py-3.5">Tanggal & sumber</th><th class="px-5 py-3.5">Menu</th><th class="px-5 py-3.5 text-right">Porsi</th><th class="px-5 py-3.5 text-right">Bahan</th><th class="px-5 py-3.5 text-right">Berat</th><th class="px-5 py-3.5">Pengadaan</th><th class="px-5 py-3.5 text-right">Aksi</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($plans as $plan)
                            <tr class="hover:bg-sky-50/30">
                                <td class="px-5 py-4"><p class="text-sm font-bold text-slate-800">{{ $plan->requirement_date?->translatedFormat('d M Y') }}</p><p class="mt-1 text-xs text-slate-400">{{ $plan->fieldDistributionPlan?->plan_number ?? $plan->plan_number }}</p></td>
                                <td class="px-5 py-4 text-sm text-slate-600">{{ $plan->menu?->name ?? 'Menu tidak tersedia' }}</td>
                                <td class="px-5 py-4 text-right text-sm font-bold text-slate-700">{{ number_format($plan->total_portions, 0, ',', '.') }}</td>
                                <td class="px-5 py-4 text-right text-sm text-slate-600">{{ $plan->total_items }}</td>
                                <td class="px-5 py-4 text-right text-sm font-bold text-slate-700">{{ number_format((float) $plan->total_weight_kg, 3, ',', '.') }} kg</td>
                                <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold {{ $plan->procurementRequest ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }}">{{ $plan->procurementRequest ? 'Draft tersedia' : 'Belum dibuat' }}</span></td>
                                <td class="px-5 py-4 text-right"><a wire:navigate href="{{ route('v3.nutrition.requirements.show', $plan) }}" class="rounded-lg bg-sky-50 px-3 py-2 text-[10px] font-bold text-sky-700">Lihat</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-16 text-center"><p class="text-sm font-bold text-slate-700">Belum ada kebutuhan bahan</p><p class="mt-1 text-xs text-slate-400">Konfirmasi porsi pada Rencana Distribusi, lalu pilih Hitung kebutuhan & pengadaan.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($plans->hasPages())<div class="border-t border-slate-100 px-5 py-4">{{ $plans->links() }}</div>@endif
        </section>
    </div>
</x-v3.shell>
