<x-v3.shell :$unit :$navigation :$roleLabel title="Ringkasan Periode Penerima" eyebrow="Snapshot 14 hari">
    <div class="mx-auto max-w-[1400px] space-y-5">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div><a wire:navigate href="{{ route('v3.beneficiary-periods.index') }}" class="inline-flex items-center gap-2 text-xs font-bold text-sky-700 hover:text-sky-900"><x-v3.icon name="arrow-left" class="size-4" /> Kembali ke periode</a><h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950">{{ $period->name }}</h2><p class="mt-1 text-sm text-slate-500">{{ $period->code }} · {{ $period->start_date->translatedFormat('d M') }}–{{ $period->end_date->translatedFormat('d M Y') }}</p></div>
            <div class="flex flex-wrap gap-2">
                @if ($period->isEditable() && (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.update')))<a wire:navigate href="{{ route('v3.beneficiary-periods.edit', $period) }}" class="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 hover:bg-slate-50"><x-v3.icon name="pencil" class="size-4" /> Ubah identitas</a>@endif
                @if ((auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.export')) && in_array($period->status, ['approved', 'active', 'closed'], true))
                    <a href="{{ route('beneficiary-periods.pdf', $period) }}" target="_blank" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 hover:bg-slate-50">PDF</a><a href="{{ route('beneficiary-periods.excel', $period) }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 hover:bg-slate-50">Excel</a>
                @endif
            </div>
        </div>

        @if ($actionMessage)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ $actionMessage }}</div>@endif
        @error('action')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        @php($statusClass = match($period->status) { 'active' => 'bg-emerald-50 text-emerald-700 ring-emerald-100', 'submitted' => 'bg-amber-50 text-amber-700 ring-amber-100', 'revision_required' => 'bg-rose-50 text-rose-700 ring-rose-100', 'approved' => 'bg-sky-50 text-sky-700 ring-sky-100', default => 'bg-slate-100 text-slate-600 ring-slate-200' })
        <section class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_360px]">
            <div class="rounded-[26px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7">
                <div class="flex flex-wrap items-start justify-between gap-4"><div><span class="rounded-full px-3 py-1 text-[10px] font-bold ring-1 {{ $statusClass }}">{{ $period->statusLabel() }}</span><h3 class="mt-4 text-2xl font-bold">{{ $period->document_number ?: 'Dokumen belum diterbitkan' }}</h3><p class="mt-2 text-sm text-slate-300">Revisi {{ $period->revision_number }} · {{ $period->notes ?: 'Tanpa catatan periode' }}</p></div><div class="rounded-2xl border border-white/10 bg-white/[.07] px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-slate-400">Durasi</p><p class="mt-1 text-xl font-bold">14 hari</p></div></div>
                <div class="mt-7 grid gap-3 sm:grid-cols-3"><div class="rounded-2xl border border-white/10 bg-white/[.06] p-4"><p class="text-[10px] uppercase tracking-wider text-slate-400">Instansi</p><p class="mt-2 text-2xl font-bold">{{ $period->destination_count }}</p></div><div class="rounded-2xl border border-white/10 bg-white/[.06] p-4"><p class="text-[10px] uppercase tracking-wider text-slate-400">Total penerima</p><p class="mt-2 text-2xl font-bold">{{ $period->total_members }}</p></div><div class="rounded-2xl border border-cyan-300/20 bg-cyan-300/10 p-4"><p class="text-[10px] uppercase tracking-wider text-cyan-200/70">Penerima aktif</p><p class="mt-2 text-2xl font-bold text-cyan-100">{{ $period->active_members }}</p></div></div>
            </div>

            <aside class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Workflow</p><h3 class="mt-1 text-base font-bold text-slate-950">Tindakan periode</h3>
                <label class="mt-4 block"><span class="mb-2 block text-xs font-semibold text-slate-600">Catatan tindakan</span><textarea wire:model="workflowNotes" rows="3" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100" placeholder="Opsional, wajib untuk revisi"></textarea>@error('workflowNotes')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                <div class="mt-4 grid gap-2">
                    @if ($period->isEditable() && (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.import')))
                        <label class="mb-1 flex items-start gap-2 rounded-xl bg-slate-50 p-3"><input wire:model="replaceExisting" type="checkbox" class="mt-0.5 size-4 rounded border-slate-300 text-sky-700"><span class="text-xs leading-5 text-slate-600">Hapus snapshot lama sebelum mengambil master.</span></label>
                        <button wire:click="importCurrentMaster" wire:confirm="Ambil master penerima aktif ke periode ini?" class="h-10 rounded-xl bg-sky-50 px-3 text-xs font-bold text-sky-700 ring-1 ring-sky-100">Ambil master penerima aktif</button>
                    @endif
                    @if ($period->isEditable() && (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.update')))<button wire:click="promoteClasses" wire:confirm="Proses kenaikan kelas pada snapshot ini?" class="h-10 rounded-xl bg-amber-50 px-3 text-xs font-bold text-amber-700 ring-1 ring-amber-100">Proses kenaikan kelas</button>@endif
                    @if ($period->isEditable() && (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.submit')))<button wire:click="submit" wire:confirm="Ajukan periode ini untuk persetujuan?" class="h-10 rounded-xl bg-[#081d3a] px-3 text-xs font-bold text-white">Ajukan periode</button>@endif
                    @if ($period->status === 'submitted' && (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.approve')))<button wire:click="approve" wire:confirm="Setujui dan kunci periode ini?" class="h-10 rounded-xl bg-emerald-600 px-3 text-xs font-bold text-white">Setujui & kunci</button><button wire:click="requestRevision" class="h-10 rounded-xl bg-rose-50 px-3 text-xs font-bold text-rose-700 ring-1 ring-rose-100">Minta revisi</button>@endif
                    @if ($period->status === 'approved' && (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.activate')))<button wire:click="activate" wire:confirm="Aktifkan periode ini? Periode aktif lama akan ditutup otomatis." class="h-10 rounded-xl bg-emerald-600 px-3 text-xs font-bold text-white">Aktifkan periode</button>@endif
                    @if (in_array($period->status, ['approved', 'active'], true) && (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.close')))<button wire:click="close" wire:confirm="Tutup periode ini?" class="h-10 rounded-xl border border-slate-200 px-3 text-xs font-bold text-slate-600 hover:bg-slate-50">Tutup periode</button>@endif
                </div>
            </aside>
        </section>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 p-5"><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Tujuan layanan</p><h3 class="mt-1 text-lg font-bold text-slate-950">Instansi dalam snapshot</h3></div>
                <div class="grid gap-3 p-5 sm:grid-cols-2">
                    @forelse ($period->destinations as $destination)
                        <article class="rounded-2xl border border-slate-100 bg-slate-50/60 p-4"><div class="flex items-start justify-between gap-3"><div><p class="text-sm font-bold text-slate-800">{{ $destination->destination_name_snapshot }}</p><p class="mt-1 text-xs text-slate-500">{{ $destination->destination_type === 'school' ? 'Sekolah' : 'Posyandu' }} · {{ $destination->destination_code_snapshot ?: 'Tanpa kode' }}</p></div><span class="rounded-full bg-sky-50 px-2.5 py-1 text-[10px] font-bold text-sky-700">{{ $destination->active_members_count }} aktif</span></div><p class="mt-3 line-clamp-2 text-xs leading-5 text-slate-500">{{ $destination->address_snapshot ?: 'Alamat belum tersedia' }}</p></article>
                    @empty<div class="rounded-2xl border border-dashed border-slate-200 p-8 text-center sm:col-span-2"><p class="text-sm font-bold text-slate-700">Snapshot masih kosong</p><p class="mt-1 text-xs text-slate-500">Gunakan aksi “Ambil master penerima aktif”.</p></div>@endforelse
                </div>
            </section>

            <div class="space-y-5">
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Komposisi</p><h3 class="mt-1 text-base font-bold text-slate-950">Penerima per kategori</h3><div class="mt-4 space-y-2">@forelse ($categorySummary as $category)<div class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5"><span class="text-xs font-medium text-slate-600">{{ $category->category_name }}</span><strong class="text-sm text-slate-900">{{ $category->total }}</strong></div>@empty<p class="text-xs text-slate-500">Belum ada data kategori.</p>@endforelse</div></section>
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Audit</p><h3 class="mt-1 text-base font-bold text-slate-950">Riwayat terbaru</h3><div class="mt-4 space-y-3">@forelse ($period->histories as $history)<div class="border-l-2 border-sky-200 pl-3"><p class="text-xs font-bold text-slate-700">{{ str($history->action)->replace('_', ' ')->title() }}</p><p class="mt-0.5 text-[10px] text-slate-500">{{ $history->user?->name ?? 'Sistem' }} · {{ $history->created_at->translatedFormat('d M Y H:i') }}</p>@if ($history->notes)<p class="mt-1 text-xs leading-5 text-slate-500">{{ $history->notes }}</p>@endif</div>@empty<p class="text-xs text-slate-500">Belum ada riwayat tindakan.</p>@endforelse</div></section>
            </div>
        </div>
    </div>
</x-v3.shell>
