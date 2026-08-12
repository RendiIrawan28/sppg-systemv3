<div>
<x-v3.shell :$unit :$navigation :$roleLabel title="Ringkasan Periode Penerima" eyebrow="Periode penerima 14 hari">
    @php
        $canUpdate = auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.update');
        $canSubmit = auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.submit');
        $canApprove = auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.approve');
        $canActivate = auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.activate');
        $canClose = auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.close');
        $canExport = auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.export');
        $editable = $period->isEditable();
        $official = in_array($period->status, ['approved', 'active', 'closed'], true);
    @endphp

    <div class="mx-auto max-w-[1400px] space-y-5">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div>
                <a wire:navigate href="{{ route('v3.beneficiary-periods.index') }}" class="inline-flex items-center gap-2 text-xs font-bold text-sky-700 hover:text-sky-900"><x-v3.icon name="arrow-left" class="size-4" /> Kembali ke periode penerima</a>
                <h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950">{{ $period->name }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $period->code }} · {{ $period->start_date->translatedFormat('d M') }}–{{ $period->end_date->translatedFormat('d M Y') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($editable && $canUpdate)
                    <a wire:navigate href="{{ route('v3.beneficiary-periods.edit', $period) }}" class="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 hover:bg-slate-50"><x-v3.icon name="pencil" class="size-4" /> Ubah draft</a>
                @endif
                @if ($canExport && $official)
                    <a href="{{ route('beneficiary-periods.pdf', $period) }}" target="_blank" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 hover:bg-slate-50">PDF</a>
                    <a href="{{ route('beneficiary-periods.excel', $period) }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 hover:bg-slate-50">Excel</a>
                @endif
            </div>
        </div>

        @if ($actionMessage)
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">{{ $actionMessage }}</div>
        @endif
        @error('action')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        <section class="rounded-[26px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-full bg-cyan-300/15 px-3 py-1 text-[10px] font-bold text-cyan-100 ring-1 ring-cyan-300/20">{{ strtoupper($period->statusLabel()) }}</span>
                        <span class="rounded-full bg-white/10 px-3 py-1 text-[10px] font-bold text-slate-200 ring-1 ring-white/10">{{ $inputMode === 'manual' ? 'INPUT JUMLAH MANUAL' : 'SNAPSHOT MASTER PENERIMA' }}</span>
                    </div>
                    <h3 class="mt-4 text-2xl font-bold">{{ $inputMode === 'manual' ? 'Jumlah penerima per kategori' : 'Data penerima berdasarkan nama' }}</h3>
                    <p class="mt-2 max-w-2xl text-sm text-slate-300">{{ $period->notes ?: 'Periode ini menjadi sumber jumlah penerima untuk menu, kebutuhan bahan, dan rencana lapangan setelah diaktifkan.' }}</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/[.07] px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-slate-400">Durasi</p><p class="mt-1 text-xl font-bold">14 hari</p></div>
            </div>
            <div class="mt-7 grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-white/10 bg-white/[.06] p-4"><p class="text-[10px] uppercase tracking-wider text-slate-400">Sekolah/Posyandu</p><p class="mt-2 text-2xl font-bold">{{ $period->destination_count }}</p></div>
                <div class="rounded-2xl border border-cyan-300/20 bg-cyan-300/10 p-4"><p class="text-[10px] uppercase tracking-wider text-cyan-200/70">Penerima aktif</p><p class="mt-2 text-2xl font-bold text-cyan-100">{{ number_format($period->active_members, 0, ',', '.') }}</p></div>
                <div class="rounded-2xl border border-white/10 bg-white/[.06] p-4"><p class="text-[10px] uppercase tracking-wider text-slate-400">Revisi</p><p class="mt-2 text-2xl font-bold">{{ $period->revision_number }}</p></div>
            </div>
        </section>

        @if ($editable && $canUpdate)
            <section class="rounded-2xl border border-sky-200 bg-sky-50 p-5 shadow-sm">
                <div><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Salin periode sebelumnya</p><h3 class="mt-1 text-lg font-bold text-slate-950">Gunakan data periode yang sudah ada</h3><p class="mt-1 text-xs text-slate-600">Salin seluruh sekolah/Posyandu dan jumlah per kategori dari periode sebelumnya ke draft ini.</p></div>
                <div class="mt-4 grid gap-3 lg:grid-cols-[auto_minmax(260px,1fr)_auto]">
                    @if($inputMode === 'master')
                        <button type="button" wire:click="refreshSnapshot" wire:confirm="Ganti snapshot dengan seluruh penerima aktif saat ini?" class="h-11 rounded-xl bg-sky-700 px-4 text-xs font-bold text-white">Perbarui snapshot master</button>
                    @else
                        <div class="hidden lg:block"></div>
                    @endif
                    <div class="flex min-w-0 gap-2">
                        <select wire:model="sourcePeriodId" class="h-11 min-w-0 flex-1 rounded-xl border border-sky-200 bg-white px-3 text-sm text-slate-800">
                            <option value="">Pilih periode sumber</option>
                            @foreach($sourcePeriods as $source)
                                <option value="{{ $source->id }}">{{ $source->code }} — {{ $source->name }}</option>
                            @endforeach
                        </select>
                        <button type="button" wire:click="copyPeriod" wire:confirm="Ganti seluruh isi draft dengan data dari periode yang dipilih?" class="h-11 shrink-0 rounded-xl border border-sky-300 bg-white px-4 text-xs font-bold text-sky-800">Salin data</button>
                    </div>
                    @if($inputMode === 'master')
                        <button type="button" wire:click="promoteClasses" wire:confirm="Naikkan kelas seluruh siswa pada snapshot ini?" class="h-11 rounded-xl bg-amber-500 px-4 text-xs font-bold text-white">Kenaikan kelas massal</button>
                    @else
                        <div class="hidden lg:block"></div>
                    @endif
                </div>
                @error('sourcePeriodId')<span class="mt-2 block text-xs text-rose-600">{{ $message }}</span>@enderror
            </section>
        @endif

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-100 p-5"><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Tujuan layanan</p><h3 class="mt-1 text-lg font-bold text-slate-950">Jumlah per sekolah atau Posyandu</h3></div>
                <div class="grid gap-3 p-5 sm:grid-cols-2">
                    @forelse ($period->destinations as $destination)
                        @php($destinationTotal = $inputMode === 'manual' ? $destination->categoryTotals->sum('total_beneficiaries') : $destination->active_members_count)
                        <article class="rounded-2xl border border-slate-100 bg-slate-50/60 p-4">
                            <div class="flex items-start justify-between gap-3"><div><p class="text-sm font-bold text-slate-800">{{ $destination->destination_name_snapshot }}</p><p class="mt-1 text-xs text-slate-500">{{ $destination->destination_type === 'school' ? 'Sekolah' : 'Posyandu' }}</p></div><span class="rounded-full bg-sky-50 px-2.5 py-1 text-[10px] font-bold text-sky-700">{{ number_format($destinationTotal, 0, ',', '.') }} penerima</span></div>
                            <div class="mt-3 space-y-1.5">
                                @if($inputMode === 'manual')
                                    @foreach($destination->categoryTotals as $total)<div class="flex justify-between gap-3 text-xs"><span class="text-slate-500">{{ $total->beneficiary_category_name_snapshot }}</span><strong class="text-slate-800">{{ number_format($total->total_beneficiaries, 0, ',', '.') }}</strong></div>@endforeach
                                @else
                                    <p class="text-xs text-slate-500">Snapshot berisi {{ number_format($destinationTotal, 0, ',', '.') }} nama penerima aktif.</p>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-200 p-8 text-center sm:col-span-2"><p class="text-sm font-bold text-slate-700">Belum ada data penerima</p><p class="mt-1 text-xs text-slate-500">Ubah draft atau perbarui snapshot terlebih dahulu.</p></div>
                    @endforelse
                </div>
            </section>

            <div class="space-y-5">
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Komposisi</p><h3 class="mt-1 text-base font-bold text-slate-950">Total per kategori</h3>
                    <div class="mt-4 space-y-2">@forelse ($categorySummary as $category)<div class="flex items-center justify-between rounded-xl bg-slate-50 px-3 py-2.5"><span class="text-xs font-medium text-slate-600">{{ $category->category_name }}</span><strong class="text-sm text-slate-900">{{ number_format($category->total, 0, ',', '.') }}</strong></div>@empty<p class="text-xs text-slate-500">Belum ada data kategori.</p>@endforelse</div>
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Alur persetujuan</p><h3 class="mt-1 text-base font-bold text-slate-950">{{ $period->statusLabel() }}</h3>
                    @if($period->status !== 'closed')
                        <label class="mt-4 block"><span class="mb-1.5 block text-xs font-semibold text-slate-600">Catatan aksi</span><textarea wire:model="workflowNotes" rows="3" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Wajib saat meminta revisi"></textarea></label>
                        @error('workflowNotes')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                        <div class="mt-3 flex flex-wrap gap-2">
                            @if(in_array($period->status, ['draft', 'revision_required'], true) && $canSubmit)<button type="button" wire:click="workflow('submit')" wire:confirm="Ajukan periode ini untuk persetujuan?" class="h-10 rounded-xl bg-violet-600 px-4 text-xs font-bold text-white">Ajukan</button>@endif
                            @if($period->status === 'submitted' && $canApprove)
                                <button type="button" wire:click="workflow('approve')" wire:confirm="Setujui dan kunci periode ini?" class="h-10 rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white">Setujui</button>
                                <button type="button" wire:click="workflow('revision')" wire:confirm="Kembalikan periode untuk revisi?" class="h-10 rounded-xl bg-rose-600 px-4 text-xs font-bold text-white">Minta revisi</button>
                            @endif
                            @if($period->status === 'approved' && $canActivate)<button type="button" wire:click="workflow('activate')" wire:confirm="Aktifkan periode ini sebagai sumber modul berikutnya?" class="h-10 rounded-xl bg-sky-700 px-4 text-xs font-bold text-white">Aktifkan</button>@endif
                            @if(in_array($period->status, ['approved', 'active'], true) && $canClose)<button type="button" wire:click="workflow('close')" wire:confirm="Tutup periode ini?" class="h-10 rounded-xl bg-slate-700 px-4 text-xs font-bold text-white">Tutup</button>@endif
                        </div>
                    @endif
                    @if($period->document_number)<p class="mt-4 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600">Dokumen: <strong>{{ $period->document_number }}</strong></p>@endif
                </section>

                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Audit</p><h3 class="mt-1 text-base font-bold text-slate-950">Riwayat terbaru</h3>
                    <div class="mt-4 space-y-3">@forelse ($period->histories as $history)<div class="border-l-2 border-sky-200 pl-3"><p class="text-xs font-bold text-slate-700">{{ str($history->action)->replace('_', ' ')->title() }}</p><p class="mt-0.5 text-[10px] text-slate-500">{{ $history->user?->name ?? 'Sistem' }} · {{ $history->created_at->translatedFormat('d M Y H:i') }}</p>@if($history->notes)<p class="mt-1 text-xs text-slate-600">{{ $history->notes }}</p>@endif</div>@empty<p class="text-xs text-slate-500">Belum ada riwayat tindakan.</p>@endforelse</div>
                </section>
            </div>
        </div>
    </div>
</x-v3.shell>
</div>
