<x-v3.shell :$unit :$navigation :$roleLabel title="Keamanan" eyebrow="Pemantauan situasi setiap tiga jam">
    <div wire:poll.60s class="mx-auto max-w-[1400px] space-y-5">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-sky-700">Staf Keamanan</p>
                <h2 class="mt-2 text-2xl font-bold text-slate-950">Pemantauan keamanan SPPG</h2>
                <p class="mt-1 text-sm text-slate-500">Satu shift berlangsung 12 jam dengan empat laporan situasi.</p>
            </div>
            @if($canWrite)
                <a wire:navigate href="{{ route('v3.security.incidents.create') }}" class="inline-flex h-11 items-center justify-center rounded-xl bg-rose-600 px-5 text-sm font-bold text-white">Laporkan insiden</a>
            @endif
        </div>

        @if($actionMessage)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ $actionMessage }}</div>@endif
        @error('action')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        @if($canWrite)
            <div class="flex items-start gap-3 rounded-2xl border px-4 py-3 text-sm {{ $notificationReady ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-amber-200 bg-amber-50 text-amber-800' }}">
                <span class="mt-0.5 size-2 shrink-0 rounded-full {{ $notificationReady ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                <div><p class="font-bold">Pengingat perangkat {{ $notificationReady ? 'siap' : 'perlu diaktifkan' }}</p><p class="mt-0.5 text-xs opacity-80">{{ $notificationMessage }}</p></div>
            </div>
        @endif

        @if($canWrite)
            <section class="overflow-hidden rounded-[28px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7">
                @if(!$activeShift)
                    <div class="flex flex-col justify-between gap-5 sm:flex-row sm:items-center">
                        <div><p class="text-xs font-bold uppercase tracking-[.16em] text-cyan-300">Belum bertugas</p><h3 class="mt-2 text-2xl font-bold">Mulai shift keamanan</h3><p class="mt-2 text-sm text-slate-300">Pengingat pertama muncul tiga jam setelah shift dimulai.</p></div>
                        <button wire:click="startShift" wire:confirm="Mulai shift keamanan selama 12 jam?" class="h-11 rounded-xl bg-cyan-300 px-5 text-sm font-bold text-[#081d3a]">Mulai Shift</button>
                    </div>
                @else
                    <div class="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                        <div><p class="text-xs font-bold uppercase tracking-[.16em] text-cyan-300">Shift aktif</p><h3 class="mt-2 text-2xl font-bold">{{ $activeShift->officer_name_snapshot }}</h3><p class="mt-2 text-sm text-slate-300">{{ $activeShift->started_at->format('H:i') }}–{{ $activeShift->scheduled_end_at->format('H:i') }} · {{ $activeShift->reports->count() }} dari 4 laporan</p></div>
                        <div class="rounded-2xl border px-5 py-4 {{ $reportDue ? 'border-amber-300/40 bg-amber-300/15' : 'border-white/10 bg-white/[.07]' }}">
                            <p class="text-[10px] font-bold uppercase tracking-[.14em] {{ $reportDue ? 'text-amber-200' : 'text-slate-400' }}">Pengingat</p>
                            @if($reportDue)
                                <p class="mt-1 font-bold text-amber-100">Laporan jam ke-{{ $activeShift->next_report_sequence }} sudah waktunya.</p>
                            @else
                                <p class="mt-1 font-bold">Laporan jam ke-{{ $activeShift->next_report_sequence }} pada {{ $nextDueAt?->format('H:i') }}</p>
                                <p class="mt-1 text-xs text-slate-300">{{ $nextDueAt?->diffForHumans() }}</p>
                            @endif
                        </div>
                    </div>
                @endif
            </section>

            @if($activeShift && $reportDue)
                <form wire:submit="submitReport" class="rounded-2xl border border-amber-200 bg-white p-5 shadow-sm sm:p-6">
                    <div><p class="text-xs font-bold uppercase tracking-[.15em] text-amber-600">Laporan jam ke-{{ $activeShift->next_report_sequence }}</p><h3 class="mt-1 text-lg font-bold text-slate-900">Kondisi umum SPPG</h3></div>
                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <label><span class="mb-1 block text-xs font-semibold text-slate-600">Situasi</span><select wire:model="situation" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">@foreach($situationOptions as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                        <label><span class="mb-1 block text-xs font-semibold text-slate-600">Foto kondisi *</span><input wire:model="reportPhoto" type="file" accept="image/*" capture="environment" class="block w-full text-xs text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-sky-50 file:px-3 file:py-2 file:font-bold file:text-sky-700">@error('reportPhoto')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 p-4"><input wire:model="gateSecure" type="checkbox" class="size-5 rounded border-slate-300 text-sky-600"><span class="text-sm font-semibold text-slate-700">Gerbang dan akses aman</span></label>
                        <label class="flex items-center gap-3 rounded-xl border border-slate-200 p-4"><input wire:model="perimeterSecure" type="checkbox" class="size-5 rounded border-slate-300 text-sky-600"><span class="text-sm font-semibold text-slate-700">Lingkungan SPPG aman</span></label>
                        <label><span class="mb-1 block text-xs font-semibold text-slate-600">Aktivitas orang/kendaraan</span><textarea wire:model="accessActivity" rows="3" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Kosongkan jika tidak ada aktivitas khusus"></textarea></label>
                        <label><span class="mb-1 block text-xs font-semibold text-slate-600">Tamu yang masuk</span><textarea wire:model="visitorActivity" rows="3" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm" placeholder="Nama atau keperluan tamu, jika ada"></textarea></label>
                        <label class="sm:col-span-2"><span class="mb-1 block text-xs font-semibold text-slate-600">Catatan tambahan</span><textarea wire:model="notes" rows="3" class="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"></textarea></label>
                    </div>
                    <div class="mt-5 flex justify-end"><button type="submit" class="h-11 rounded-xl bg-emerald-600 px-5 text-sm font-bold text-white">Kirim Laporan</button></div>
                </form>
            @endif

            @if($activeShift?->reports->isNotEmpty())
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h3 class="font-bold text-slate-900">Laporan shift saat ini</h3><div class="mt-4 grid gap-3 md:grid-cols-2">@foreach($activeShift->reports as $report)<article class="rounded-xl border border-slate-200 p-4"><div class="flex items-start justify-between"><div><p class="text-xs font-bold text-sky-700">Jam ke-{{ $report->sequence_number }}</p><p class="mt-1 text-sm font-bold text-slate-800">{{ $report->situation->label() }}</p></div><span class="text-xs text-slate-400">{{ $report->reported_at->format('H:i') }}</span></div><p class="mt-3 text-xs text-slate-500">Gerbang: {{ $report->gate_secure ? 'Aman' : 'Perlu perhatian' }} · Lingkungan: {{ $report->perimeter_secure ? 'Aman' : 'Perlu perhatian' }}</p>@if($report->photo_path)<x-v3.documentation-button :url="\Illuminate\Support\Facades\Storage::disk('public')->url($report->photo_path)" :title="'Dokumentasi laporan keamanan jam ke-'.$report->sequence_number" label="Lihat foto kondisi" class="mt-3" />@endif</article>@endforeach</div></section>
            @endif
        @endif

        <div class="grid gap-5 xl:grid-cols-2">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                    <h3 class="font-bold text-slate-900">Riwayat shift</h3>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <input wire:model.live="historyDate" type="date" aria-label="Filter tanggal shift" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-xs text-slate-700">
                        <select wire:model.live="historyOfficer" aria-label="Filter petugas keamanan" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-xs text-slate-700">
                            <option value="">Semua petugas</option>
                            @foreach($officerOptions as $officer)
                                <option value="{{ $officer->officer_id }}">{{ $officer->officer_name_snapshot }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="mt-4 space-y-3">
                    @forelse($recentShifts as $shift)
                        <article class="rounded-xl border border-slate-200 p-4">
                            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                                <div>
                                    <p class="text-sm font-bold text-slate-800">{{ $shift->officer_name_snapshot }}</p>
                                    <p class="mt-1 text-xs text-slate-400">{{ $shift->started_at->translatedFormat('d M Y, H:i') }} · {{ $shift->reports_count }}/{{ $shift->reports_expected }} laporan @if($shift->reports_count < $shift->reports_expected && $shift->status !== App\Enums\SecurityShiftStatus::Active) · {{ $shift->reports_expected - $shift->reports_count }} tidak dilaporkan @endif</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-600">{{ $shift->status->label() }}</span>
                                    <a wire:navigate href="{{ route('v3.security.shifts.show', $shift) }}" class="rounded-lg bg-sky-50 px-3 py-2 text-xs font-bold text-sky-700">Lihat rincian</a>
                                </div>
                            </div>
                        </article>
                    @empty
                        <p class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-400">Tidak ada riwayat shift sesuai filter.</p>
                    @endforelse
                </div>
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex items-center justify-between"><h3 class="font-bold text-slate-900">Insiden keamanan</h3>@if($canWrite)<a wire:navigate href="{{ route('v3.security.incidents.create') }}" class="text-xs font-bold text-rose-600">+ Laporkan</a>@endif</div><div class="mt-4 space-y-3">@forelse($incidents as $incident)<a wire:navigate href="{{ route('v3.security.incidents.show', $incident) }}" class="block rounded-xl border border-slate-200 p-4"><div class="flex items-start justify-between gap-3"><div><p class="text-sm font-bold text-slate-800">{{ $incident->title }}</p><p class="mt-1 text-xs text-slate-400">{{ $incident->occurred_at?->translatedFormat('d M Y, H:i') }}</p></div><span class="rounded-full bg-rose-50 px-2.5 py-1 text-[10px] font-bold text-rose-700">{{ $incident->status->label() }}</span></div></a>@empty<p class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-400">Tidak ada insiden keamanan.</p>@endforelse</div></section>
        </div>
    </div>
</x-v3.shell>
