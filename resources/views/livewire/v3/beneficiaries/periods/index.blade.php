<x-v3.shell :$unit :$navigation :$roleLabel title="Periode Penerima" eyebrow="Snapshot 14 hari">
    <div class="mx-auto max-w-[1400px] space-y-5">
        @if (session('v3.status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('v3.status') }}</div>
        @endif

        <section class="relative overflow-hidden rounded-[26px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7">
            <div class="absolute -right-20 -top-24 size-72 rounded-full bg-cyan-300/10"></div>
            <div class="relative flex flex-col justify-between gap-6 lg:flex-row lg:items-end">
                <div>
                    <span class="inline-flex rounded-full bg-cyan-300/10 px-3 py-1 text-[10px] font-bold uppercase tracking-[.16em] text-cyan-200 ring-1 ring-cyan-300/20">Master operasional</span>
                    <h2 class="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">Snapshot penerima setiap 14 hari.</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">Bekukan instansi, kategori, kelompok, dan penerima aktif sebagai dasar menu serta rencana distribusi.</p>
                </div>
                <div class="relative flex flex-wrap gap-3">
                    <a wire:navigate href="{{ route('v3.beneficiaries.index') }}" class="inline-flex h-11 items-center gap-2 rounded-xl border border-white/15 bg-white/10 px-4 text-xs font-bold text-white hover:bg-white/15"><x-v3.icon name="users" class="size-4" /> Master penerima</a>
                    @if (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.create'))
                        <a wire:navigate href="{{ route('v3.beneficiary-periods.create') }}" class="inline-flex h-11 items-center gap-2 rounded-xl bg-cyan-300 px-4 text-xs font-bold text-[#071a34] hover:bg-cyan-200"><x-v3.icon name="plus" class="size-4" /> Buat periode</a>
                    @endif
                </div>
            </div>
            <div class="relative mt-6 grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-white/10 bg-white/[.07] px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-slate-400">Total periode</p><p class="mt-1 text-xl font-bold">{{ number_format($periodCount, 0, ',', '.') }}</p></div>
                <div class="rounded-2xl border border-cyan-300/20 bg-cyan-300/10 px-4 py-3 sm:col-span-2"><p class="text-[10px] uppercase tracking-wider text-cyan-200/70">Periode aktif</p><p class="mt-1 text-sm font-bold text-cyan-100">{{ $activePeriod ? $activePeriod->name.' · '.$activePeriod->start_date->translatedFormat('d M').'–'.$activePeriod->end_date->translatedFormat('d M Y') : 'Belum ada periode aktif' }}</p></div>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:p-5">
                <div class="relative flex-1"><span class="pointer-events-none absolute inset-y-0 left-0 grid w-11 place-items-center text-slate-400"><x-v3.icon name="search" class="size-[18px]" /></span><input wire:model.live.debounce.350ms="search" type="search" placeholder="Cari kode, nama, atau nomor dokumen..." class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50/50 pl-11 pr-4 text-sm outline-none focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100"></div>
                <select wire:model.live="status" class="h-11 min-w-52 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-600 outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                    <option value="all">Semua status</option><option value="draft">Draft</option><option value="submitted">Menunggu persetujuan</option><option value="revision_required">Perlu revisi</option><option value="approved">Disetujui</option><option value="active">Aktif</option><option value="closed">Ditutup</option>
                </select>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[900px] text-left">
                    <thead><tr class="border-b border-slate-100 bg-slate-50/70 text-[10px] font-bold uppercase tracking-wider text-slate-400"><th class="px-5 py-3.5">Periode</th><th class="px-5 py-3.5">Rentang</th><th class="px-5 py-3.5 text-center">Instansi</th><th class="px-5 py-3.5 text-center">Penerima aktif</th><th class="px-5 py-3.5">Status</th><th class="px-5 py-3.5">Nomor dokumen</th><th class="px-5 py-3.5 text-right">Aksi</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($periods as $period)
                            @php($statusClass = match($period->status) { 'active' => 'bg-emerald-50 text-emerald-700 ring-emerald-100', 'submitted' => 'bg-amber-50 text-amber-700 ring-amber-100', 'revision_required' => 'bg-rose-50 text-rose-700 ring-rose-100', 'approved' => 'bg-sky-50 text-sky-700 ring-sky-100', default => 'bg-slate-100 text-slate-600 ring-slate-200' })
                            <tr class="hover:bg-sky-50/30"><td class="px-5 py-4"><p class="text-sm font-bold text-slate-800">{{ $period->name }}</p><p class="mt-0.5 text-xs text-slate-400">{{ $period->code }} · Rev {{ $period->revision_number }}</p></td><td class="px-5 py-4 text-sm text-slate-600">{{ $period->start_date->translatedFormat('d M') }}–{{ $period->end_date->translatedFormat('d M Y') }}</td><td class="px-5 py-4 text-center text-sm font-bold text-slate-700">{{ $period->destination_count }}</td><td class="px-5 py-4 text-center text-sm font-bold text-slate-700">{{ $period->active_members }}</td><td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold ring-1 {{ $statusClass }}">{{ $period->statusLabel() }}</span></td><td class="px-5 py-4 text-xs text-slate-500">{{ $period->document_number ?: '—' }}</td><td class="px-5 py-4"><div class="flex justify-end gap-2">@if ($period->isEditable() && (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.update')))<a wire:navigate href="{{ route('v3.beneficiary-periods.edit', $period) }}" class="grid size-8 place-items-center rounded-lg bg-slate-100 text-slate-600" aria-label="Ubah periode"><x-v3.icon name="pencil" class="size-4" /></a>@endif<a wire:navigate href="{{ route('v3.beneficiary-periods.show', $period) }}" class="rounded-lg bg-sky-50 px-3 py-2 text-[10px] font-bold text-sky-700 ring-1 ring-sky-100">Ringkasan</a></div></td></tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-16 text-center"><span class="mx-auto grid size-12 place-items-center rounded-2xl bg-slate-100 text-slate-400"><x-v3.icon name="calendar" class="size-5" /></span><p class="mt-4 text-sm font-bold text-slate-700">Periode belum tersedia</p><p class="mt-1 text-xs text-slate-400">Buat periode pertama untuk mengambil snapshot master penerima aktif.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($periods->hasPages())<div class="border-t border-slate-100 px-5 py-4">{{ $periods->links() }}</div>@endif
        </section>
    </div>
</x-v3.shell>
