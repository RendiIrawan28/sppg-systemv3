<x-v3.shell :$unit :$navigation :$roleLabel title="Laporan Harian Lapangan" eyebrow="Rekap setelah pengiriman dan pengambilan ompreng selesai">
    <div class="mx-auto max-w-[1500px] space-y-5">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.18em] text-sky-700">Lapangan</p>
            <h2 class="mt-2 text-2xl font-bold text-slate-950 dark:text-white">Laporan harian Asisten Lapangan</h2>
            <p class="mt-1 text-sm text-slate-500">Rekap dibuat sebagai Draft setelah seluruh pengantaran dan pengambilan ompreng selesai. Asisten Lapangan melengkapi evaluasi lalu mengajukan kepada Kepala SPPG.</p>
        </div>

        @error('workflow')<div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{!! $message !!}</div>@enderror

        <div class="grid gap-5 xl:grid-cols-[.9fr_1.4fr]">
            <div class="space-y-3">
                @forelse($reports as $report)
                    <button type="button" wire:click="select({{ $report->id }})" class="w-full rounded-2xl border p-4 text-left shadow-sm transition {{ $selectedId === $report->id ? 'border-sky-500 bg-sky-50 dark:bg-sky-950/30' : 'border-slate-200 bg-white hover:border-sky-300 dark:border-slate-700 dark:bg-slate-900' }}">
                        <div class="flex items-start justify-between gap-3">
                            <div><p class="text-xs font-bold text-sky-700">{{ $report->report_number }}</p><p class="mt-1 font-bold text-slate-900 dark:text-white">{{ $report->report_date?->translatedFormat('l, d F Y') }}</p></div>
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-200">{{ $report->status->label() }}</span>
                        </div>
                        <p class="mt-2 text-xs text-slate-500">Terkirim {{ number_format($report->delivered_portions) }} porsi · Ompreng kembali {{ number_format($report->containers_returned) }}/{{ number_format($report->containers_sent) }}</p>
                    </button>
                @empty
                    <div class="rounded-2xl border border-dashed border-slate-200 bg-white px-5 py-14 text-center text-sm text-slate-400 dark:border-slate-700 dark:bg-slate-900">Belum ada laporan. Laporan akan dibuat setelah seluruh ompreng pada tanggal pelayanan selesai diambil.</div>
                @endforelse
                @if($reports->hasPages()){{ $reports->links() }}@endif
            </div>

            @if($selected)
                @php($editable = $selected->isEditable() && $canUpdate)
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900">
                    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-start">
                        <div><p class="text-xs font-bold text-sky-700">{{ $selected->report_number }}</p><h3 class="mt-1 text-xl font-bold text-slate-950 dark:text-white">{{ $selected->report_date?->translatedFormat('l, d F Y') }}</h3><p class="mt-1 text-xs text-slate-500">{{ $selected->status->label() }} · Disiapkan oleh {{ $selected->prepared_by_name_snapshot ?: '-' }}</p></div>
                        <div class="flex gap-2"><a href="{{ route('field-daily-reports.pdf', $selected) }}" target="_blank" class="rounded-xl border px-3 py-2 text-xs font-bold dark:border-slate-700">PDF</a><a href="{{ route('field-daily-reports.excel', $selected) }}" class="rounded-xl border px-3 py-2 text-xs font-bold dark:border-slate-700">Excel</a></div>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @foreach(['delivered_portions'=>'Porsi terkirim','returned_portions'=>'Porsi kembali','containers_sent'=>'Ompreng dikirim','containers_returned'=>'Ompreng kembali'] as $field=>$label)
                            <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-800"><p class="text-[10px] text-slate-400">{{ $label }}</p><p class="mt-1 font-bold">{{ number_format($selected->{$field}) }}</p></div>
                        @endforeach
                    </div>

                    <div class="mt-5 grid gap-4">
                        @foreach(['operational_summary'=>'Ringkasan operasional','obstacles'=>'Kendala','evaluation'=>'Evaluasi','follow_up'=>'Tindak lanjut','recommendations'=>'Rekomendasi'] as $field=>$label)
                            <label><span class="text-xs font-bold text-slate-600 dark:text-slate-300">{{ $label }}</span><textarea wire:model="form.{{ $field }}" rows="3" @disabled(!$editable) class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm disabled:bg-slate-100 dark:border-slate-700 dark:bg-slate-950 dark:disabled:bg-slate-800"></textarea>@error('form.'.$field)<span class="text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                        @endforeach
                        <label><span class="text-xs font-bold text-slate-600 dark:text-slate-300">Catatan pemeriksaan/revisi</span><textarea wire:model="reviewNotes" rows="2" class="mt-1 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950"></textarea></label>
                    </div>

                    <div class="mt-5 flex flex-wrap gap-2">
                        @if($editable)<button type="button" wire:click="refreshReport" class="rounded-xl border px-4 py-2 text-xs font-bold dark:border-slate-700">Perbarui rekap</button><button type="button" wire:click="save" class="rounded-xl bg-slate-800 px-4 py-2 text-xs font-bold text-white">Simpan</button>@endif
                        @if($selected->isEditable() && $canSubmit)<button type="button" wire:click="submit" wire:confirm="Ajukan laporan kepada Kepala SPPG?" class="rounded-xl bg-sky-600 px-4 py-2 text-xs font-bold text-white">Ajukan laporan</button>@endif
                        @if($selected->status === \App\Enums\FieldDailyReportStatus::Submitted && $canApprove)<button type="button" wire:click="approve" wire:confirm="Setujui laporan harian ini?" class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white">Setujui</button><button type="button" wire:click="requestRevision" class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-bold text-white">Minta revisi</button>@endif
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-v3.shell>
