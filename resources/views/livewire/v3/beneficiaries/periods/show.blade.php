<x-v3.shell :$unit :$navigation :$roleLabel title="Ringkasan Jumlah Penerima" eyebrow="Jumlah per tujuan dan kategori">
    <div class="mx-auto max-w-[1400px] space-y-5">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div><a wire:navigate href="{{ route('v3.beneficiary-periods.index') }}" class="inline-flex items-center gap-2 text-xs font-bold text-sky-700 hover:text-sky-900"><x-v3.icon name="arrow-left" class="size-4" /> Kembali ke data penerima</a><h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950">{{ $period->name }}</h2><p class="mt-1 text-sm text-slate-500">{{ $period->code }} · {{ $period->start_date->translatedFormat('d M') }}–{{ $period->end_date->translatedFormat('d M Y') }}</p></div>
            <div class="flex flex-wrap gap-2">
                @if (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.update'))<a wire:navigate href="{{ route('v3.beneficiary-periods.edit', $period) }}" class="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 hover:bg-slate-50"><x-v3.icon name="pencil" class="size-4" /> Ubah jumlah</a>@endif
                @if (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.export'))
                    <a href="{{ route('beneficiary-periods.pdf', $period) }}" target="_blank" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 hover:bg-slate-50">PDF</a><a href="{{ route('beneficiary-periods.excel', $period) }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 hover:bg-slate-50">Excel</a>
                @endif
            </div>
        </div>

        <section class="rounded-[26px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7">
            <div class="flex flex-wrap items-start justify-between gap-4"><div><span class="rounded-full bg-emerald-400/15 px-3 py-1 text-[10px] font-bold text-emerald-200 ring-1 ring-emerald-300/20">AKTIF LANGSUNG</span><h3 class="mt-4 text-2xl font-bold">Jumlah penerima tanpa data nama</h3><p class="mt-2 text-sm text-slate-300">{{ $period->notes ?: 'Digunakan untuk penyusunan menu dan perhitungan kebutuhan bahan.' }}</p></div><div class="rounded-2xl border border-white/10 bg-white/[.07] px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-slate-400">Durasi</p><p class="mt-1 text-xl font-bold">14 hari</p></div></div>
            <div class="mt-7 grid gap-3 sm:grid-cols-2"><div class="rounded-2xl border border-white/10 bg-white/[.06] p-4"><p class="text-[10px] uppercase tracking-wider text-slate-400">Sekolah/Posyandu</p><p class="mt-2 text-2xl font-bold">{{ $period->destination_count }}</p></div><div class="rounded-2xl border border-cyan-300/20 bg-cyan-300/10 p-4"><p class="text-[10px] uppercase tracking-wider text-cyan-200/70">Total penerima</p><p class="mt-2 text-2xl font-bold text-cyan-100">{{ number_format($period->active_members, 0, ',', '.') }}</p></div></div>
        </section>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 p-5"><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Tujuan layanan</p><h3 class="mt-1 text-lg font-bold text-slate-950">Jumlah per sekolah atau Posyandu</h3></div>
                <div class="grid gap-3 p-5 sm:grid-cols-2">
                    @forelse ($period->destinations as $destination)
                        @php($destinationTotal = $destination->categoryTotals->sum('total_beneficiaries'))
                        <article class="rounded-2xl border border-slate-100 bg-slate-50/60 p-4">
                            <div class="flex items-start justify-between gap-3"><div><p class="text-sm font-bold text-slate-800">{{ $destination->destination_name_snapshot }}</p><p class="mt-1 text-xs text-slate-500">{{ $destination->destination_type === 'school' ? 'Sekolah' : 'Posyandu' }}</p></div><span class="rounded-full bg-sky-50 px-2.5 py-1 text-[10px] font-bold text-sky-700">{{ number_format($destinationTotal ?: $destination->activeMembers()->count(), 0, ',', '.') }} penerima</span></div>
                            <div class="mt-3 space-y-1.5">
                                @forelse($destination->categoryTotals as $total)
                                    <div class="flex justify-between gap-3 text-xs"><span class="text-slate-500">{{ $total->beneficiary_category_name_snapshot }}</span><strong class="text-slate-800">{{ number_format($total->total_beneficiaries, 0, ',', '.') }}</strong></div>
                                @empty
                                    <p class="text-xs text-slate-500">Data lama berdasarkan nama penerima.</p>
                                @endforelse
                            </div>
                        </article>
                    @empty<div class="rounded-2xl border border-dashed border-slate-200 p-8 text-center sm:col-span-2"><p class="text-sm font-bold text-slate-700">Belum ada tujuan</p><p class="mt-1 text-xs text-slate-500">Gunakan tombol “Ubah jumlah”.</p></div>@endforelse
                </div>
            </section>

            <div class="space-y-5">
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Komposisi</p><h3 class="mt-1 text-base font-bold text-slate-950">Total per kategori</h3><div class="mt-4 space-y-2">@forelse ($categorySummary as $category)<div class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5"><span class="text-xs font-medium text-slate-600">{{ $category->category_name }}</span><strong class="text-sm text-slate-900">{{ number_format($category->total, 0, ',', '.') }}</strong></div>@empty<p class="text-xs text-slate-500">Belum ada data kategori.</p>@endforelse</div></section>
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Audit</p><h3 class="mt-1 text-base font-bold text-slate-950">Riwayat terbaru</h3><div class="mt-4 space-y-3">@forelse ($period->histories as $history)<div class="border-l-2 border-sky-200 pl-3"><p class="text-xs font-bold text-slate-700">{{ str($history->action)->replace('_', ' ')->title() }}</p><p class="mt-0.5 text-[10px] text-slate-500">{{ $history->user?->name ?? 'Sistem' }} · {{ $history->created_at->translatedFormat('d M Y H:i') }}</p></div>@empty<p class="text-xs text-slate-500">Belum ada riwayat tindakan.</p>@endforelse</div></section>
            </div>
        </div>
    </div>
</x-v3.shell>
