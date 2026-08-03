<x-v3.shell :$unit :$navigation :$roleLabel title="Rincian Shift Keamanan" eyebrow="Laporan situasi per tiga jam">
    <div class="mx-auto max-w-[1200px] space-y-5">
        <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-end">
            <div>
                <a wire:navigate href="{{ route('v3.security.index') }}" class="text-sm font-bold text-sky-700">← Kembali ke Keamanan</a>
                <p class="mt-5 text-xs font-bold uppercase tracking-[.18em] text-sky-700">Rincian Shift</p>
                <h2 class="mt-2 text-2xl font-bold text-slate-950">{{ $shift->officer_name_snapshot }}</h2>
                <p class="mt-1 text-sm text-slate-500">{{ $shift->started_at->translatedFormat('l, d F Y · H:i') }}–{{ $shift->scheduled_end_at->format('H:i') }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('v3.security.shifts.pdf', $shift) }}" target="_blank" class="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700">PDF</a>
                <a href="{{ route('v3.security.shifts.xlsx', $shift) }}" class="inline-flex h-11 items-center justify-center rounded-xl bg-emerald-600 px-4 text-sm font-bold text-white">Excel</a>
            </div>
        </div>

        <section class="overflow-hidden rounded-[28px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7">
            <div class="grid gap-5 lg:grid-cols-[1.4fr_repeat(3,minmax(0,1fr))] lg:items-center">
                <div>
                    <span class="inline-flex rounded-full bg-white/10 px-3 py-1 text-xs font-bold text-cyan-200">{{ $shift->status->label() }}</span>
                    <p class="mt-4 text-3xl font-bold">{{ $shift->reports->count() }} dari {{ $shift->reports_expected }}</p>
                    <p class="mt-1 text-sm text-slate-300">laporan situasi telah diunggah</p>
                </div>
                <div class="rounded-2xl border border-white/10 bg-white/[.06] p-4"><p class="text-xs text-slate-400">Mulai shift</p><p class="mt-1 font-bold">{{ $shift->started_at->format('d/m/Y H:i') }}</p></div>
                <div class="rounded-2xl border border-white/10 bg-white/[.06] p-4"><p class="text-xs text-slate-400">Batas shift</p><p class="mt-1 font-bold">{{ $shift->scheduled_end_at->format('d/m/Y H:i') }}</p></div>
                <div class="rounded-2xl border border-white/10 bg-white/[.06] p-4"><p class="text-xs text-slate-400">Selesai tercatat</p><p class="mt-1 font-bold">{{ $shift->completed_at?->format('d/m/Y H:i') ?: 'Belum selesai' }}</p></div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.15em] text-sky-700">Laporan Situasi</p>
                <h3 class="mt-1 text-lg font-bold text-slate-900">Empat periode pemantauan</h3>
                <p class="mt-1 text-sm text-slate-500">Data berikut berasal langsung dari laporan yang diunggah petugas keamanan.</p>
            </div>

            <div class="mt-5 space-y-4">
                @foreach($periods as $period)
                    @php($report = $period['report'])
                    <article class="rounded-2xl border {{ $report ? 'border-slate-200' : 'border-dashed border-slate-300' }} p-4 sm:p-5">
                        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                            <div>
                                <p class="text-sm font-bold text-slate-900">Laporan jam ke-{{ $period['sequence'] }}</p>
                                <p class="mt-1 text-xs text-slate-500">Target {{ $period['due_at']->translatedFormat('d M Y, H:i') }} @if($report) · Diunggah {{ $report->reported_at->format('H:i') }} @endif</p>
                            </div>
                            <span class="w-fit rounded-full px-3 py-1 text-[11px] font-bold {{ $period['status']['class'] }}">{{ $period['status']['label'] }}</span>
                        </div>

                        @if($report)
                            <div class="mt-4 grid gap-3 sm:grid-cols-3">
                                <div class="rounded-xl bg-slate-50 p-3"><p class="text-[11px] font-semibold text-slate-400">Situasi</p><p class="mt-1 text-sm font-bold text-slate-800">{{ $report->situation->label() }}</p></div>
                                <div class="rounded-xl bg-slate-50 p-3"><p class="text-[11px] font-semibold text-slate-400">Gerbang dan akses</p><p class="mt-1 text-sm font-bold {{ $report->gate_secure ? 'text-emerald-700' : 'text-amber-700' }}">{{ $report->gate_secure ? 'Aman' : 'Perlu perhatian' }}</p></div>
                                <div class="rounded-xl bg-slate-50 p-3"><p class="text-[11px] font-semibold text-slate-400">Lingkungan SPPG</p><p class="mt-1 text-sm font-bold {{ $report->perimeter_secure ? 'text-emerald-700' : 'text-amber-700' }}">{{ $report->perimeter_secure ? 'Aman' : 'Perlu perhatian' }}</p></div>
                            </div>
                            <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                                <div><dt class="text-xs font-semibold text-slate-500">Aktivitas orang/kendaraan</dt><dd class="mt-1 whitespace-pre-line text-sm text-slate-800">{{ $report->access_activity ?: 'Tidak ada catatan' }}</dd></div>
                                <div><dt class="text-xs font-semibold text-slate-500">Tamu yang masuk</dt><dd class="mt-1 whitespace-pre-line text-sm text-slate-800">{{ $report->visitor_activity ?: 'Tidak ada catatan' }}</dd></div>
                                <div class="sm:col-span-2"><dt class="text-xs font-semibold text-slate-500">Catatan tambahan</dt><dd class="mt-1 whitespace-pre-line text-sm text-slate-800">{{ $report->notes ?: 'Tidak ada catatan' }}</dd></div>
                            </dl>
                            @if($report->photo_path)
                                <x-v3.documentation-button
                                    :url="\Illuminate\Support\Facades\Storage::disk('public')->url($report->photo_path)"
                                    :title="'Dokumentasi laporan keamanan jam ke-'.$period['sequence']"
                                    label="Lihat foto kondisi"
                                    class="mt-4"
                                />
                            @endif
                        @else
                            <p class="mt-4 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-500">Belum ada data yang diunggah untuk periode ini.</p>
                        @endif
                    </article>
                @endforeach
            </div>
        </section>
    </div>
</x-v3.shell>
