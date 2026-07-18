<x-v3.shell :$unit :$navigation :$roleLabel title="Evaluasi Gizi Harian" eyebrow="Ahli gizi">
    <div class="mx-auto max-w-[1500px] space-y-5">
        <section class="relative overflow-hidden rounded-[26px] bg-[#081d3a] p-6 text-white shadow-xl shadow-slate-900/10 sm:p-7">
            <div class="absolute -right-16 -top-20 size-64 rounded-full bg-cyan-300/10"></div>
            <div class="relative flex flex-col justify-between gap-6 lg:flex-row lg:items-end">
                <div class="max-w-2xl">
                    <span class="rounded-full bg-cyan-300/10 px-3 py-1 text-[10px] font-bold uppercase tracking-[.16em] text-cyan-200 ring-1 ring-cyan-300/20">G12 + G13</span>
                    <h2 class="mt-5 text-3xl font-bold tracking-[-.035em]">Satu laporan untuk penerimaan dan capaian gizi.</h2>
                    <p class="mt-3 text-sm leading-6 text-slate-300">Hasil uji penerimaan menu ditampilkan bersama realisasi pelayanan dan komponen gizi pada tanggal yang sama.</p>
                </div>
                <div class="flex gap-3">
                    <div class="rounded-2xl border border-white/10 bg-white/[.07] px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-slate-400">Evaluasi</p><p class="mt-1 text-xl font-bold">{{ number_format($evaluationCount, 0, ',', '.') }}</p></div>
                    <div class="rounded-2xl border border-cyan-300/20 bg-cyan-300/10 px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-cyan-200/70">Laporan</p><p class="mt-1 text-xl font-bold text-cyan-100">{{ number_format($reportCount, 0, ',', '.') }}</p></div>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 p-4 sm:p-5">
                <div class="relative max-w-xl">
                    <span class="pointer-events-none absolute inset-y-0 left-0 grid w-11 place-items-center text-slate-400"><x-v3.icon name="search" class="size-[18px]" /></span>
                    <input wire:model.live.debounce.350ms="search" type="search" placeholder="Cari nomor laporan atau nama menu..." class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50/50 pl-11 pr-4 text-sm outline-none focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100">
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full min-w-[980px] text-left">
                    <thead><tr class="border-b border-slate-100 bg-slate-50/70 text-[10px] font-bold uppercase tracking-[.12em] text-slate-400">
                        <th class="px-5 py-3.5">Tanggal & menu</th><th class="px-5 py-3.5">Pelayanan</th><th class="px-5 py-3.5">Penerimaan</th><th class="px-5 py-3.5">Sisa</th><th class="px-5 py-3.5">Temuan</th><th class="px-5 py-3.5">Dokumen</th>
                    </tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($reports as $report)
                            @php
                                $key = $report->report_date->toDateString().'|'.$report->menu_id;
                                $dailyEvaluations = $evaluations->get($key, collect());
                                $acceptance = $dailyEvaluations->isNotEmpty() ? $dailyEvaluations->avg('acceptance_percent') : $report->average_acceptance_percent;
                                $waste = $dailyEvaluations->isNotEmpty() ? $dailyEvaluations->avg('waste_percent') : $report->average_waste_percent;
                            @endphp
                            <tr wire:key="nutrition-report-{{ $report->id }}" class="hover:bg-sky-50/30">
                                <td class="px-5 py-4"><p class="text-sm font-bold text-slate-800">{{ $report->report_date->translatedFormat('d M Y') }}</p><p class="mt-1 max-w-64 truncate text-xs text-slate-500">{{ $report->menu?->name ?? 'Menu tidak terhubung' }} · {{ $report->report_number }}</p></td>
                                <td class="px-5 py-4"><p class="text-sm font-bold text-slate-800">{{ number_format($report->served_portions, 0, ',', '.') }} porsi</p><p class="mt-1 text-xs text-slate-400">{{ number_format($report->actual_beneficiaries, 0, ',', '.') }} penerima aktual</p></td>
                                <td class="px-5 py-4"><p class="text-sm font-bold {{ $acceptance !== null && $acceptance < 80 ? 'text-amber-700' : 'text-emerald-700' }}">{{ $acceptance !== null ? number_format((float) $acceptance, 1, ',', '.').'%' : '—' }}</p><p class="mt-1 text-xs text-slate-400">{{ $dailyEvaluations->count() }} lokasi evaluasi</p></td>
                                <td class="px-5 py-4"><p class="text-sm font-bold text-slate-700">{{ $waste !== null ? number_format((float) $waste, 1, ',', '.').'%' : '—' }}</p><p class="mt-1 text-xs text-slate-400">{{ number_format($report->returned_portions, 0, ',', '.') }} porsi kembali</p></td>
                                <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold {{ $report->open_findings_count > 0 ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' }}">{{ $report->open_findings_count }} terbuka</span></td>
                                <td class="px-5 py-4"><div class="flex gap-2"><a href="{{ route('nutrition.daily-reports.pdf', $report) }}" target="_blank" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[10px] font-bold text-slate-600 hover:bg-slate-50">PDF</a><a href="{{ route('nutrition.daily-reports.excel', $report) }}" class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[10px] font-bold text-slate-600 hover:bg-slate-50">Excel</a></div></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-16 text-center text-sm text-slate-500">Belum ada laporan gizi harian untuk unit ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($reports->hasPages()) <div class="border-t border-slate-100 px-5 py-4">{{ $reports->links() }}</div> @endif
        </section>
    </div>
</x-v3.shell>
