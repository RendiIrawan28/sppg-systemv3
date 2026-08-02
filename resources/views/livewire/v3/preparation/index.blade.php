<x-v3.shell :$unit :$navigation :$roleLabel title="Divisi Persiapan" eyebrow="Pengambilan Gudang sampai selesai Persiapan">
    <div class="mx-auto max-w-[1500px] space-y-5">
        @if(session('v3.status')) <div class="rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('v3.status') }}</div> @endif
        @if($errors->any()) <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div> @endif

        <section class="rounded-[28px] bg-[#081d3a] p-6 text-white">
            <p class="text-xs font-bold uppercase tracking-widest text-cyan-200">Kontrol Persiapan</p>
            <h2 class="mt-2 text-2xl font-bold">Catat hasil, sisa, dan retur bahan.</h2>
            <p class="mt-2 max-w-3xl text-sm text-slate-300">Sesi muncul otomatis segera setelah pengambilan dicatat. Lengkapi hasil bersih, limbah, retur bila ada, dan satu foto hasil Persiapan.</p>
            <a wire:navigate href="{{ route('v3.preparation-outputs.index') }}" class="mt-4 inline-flex h-10 items-center rounded-xl bg-white/10 px-4 text-xs font-bold text-white hover:bg-white/20">Buka Penyimpanan Hasil Persiapan</a>
        </section>

        <div class="grid gap-5 xl:grid-cols-[350px_minmax(0,1fr)]">
            <section class="space-y-2">
                @forelse($records as $record)
                    <button wire:click="select({{ $record->id }})" class="w-full rounded-2xl border p-4 text-left {{ $selectedId === $record->id ? 'border-sky-400 bg-sky-50' : 'border-slate-200 bg-white' }}">
                        <div class="flex justify-between gap-2"><b>{{ $record->session_number }}</b><span class="text-[10px] font-bold uppercase">{{ str($record->state)->replace('_', ' ') }}</span></div>
                        <p class="mt-1 text-xs text-slate-500">{{ $record->purpose_reference }}</p>
                        <p class="mt-2 text-[10px] font-bold text-sky-700">{{ $statusLabels[$record->status->value] ?? $record->status->value }}</p>
                    </button>
                @empty
                    <div class="rounded-2xl bg-white p-8 text-center text-sm text-slate-500">Belum ada pengambilan barang yang dicatat oleh Divisi Persiapan.</div>
                @endforelse
            </section>

            @if($selected)
                <section class="space-y-5">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex flex-wrap justify-between gap-3">
                            <div><h2 class="text-xl font-bold">{{ $selected->session_number }}</h2><p class="text-sm text-slate-500">{{ $selected->purpose_reference }} · diambil oleh {{ $selected->withdrawal?->taker?->name ?: '-' }}</p></div>
                            <div class="text-right"><span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold">{{ str($selected->state)->replace('_', ' ')->title() }}</span><p class="mt-2 text-xs font-bold text-sky-700">{{ $statusLabels[$selected->status->value] ?? $selected->status->value }}</p></div>
                        </div>
                        @if($selected->withdrawal?->status === \App\Models\WarehouseWithdrawal::WAITING)
                            <p class="mt-3 rounded-xl bg-sky-50 p-3 text-xs font-semibold text-sky-700">Jumlah bahan masih berdasarkan catatan pengambilan. Gudang belum menyelesaikan pemeriksaan stok.</p>
                        @endif
                        @if($canExport && $selected->status === \App\Enums\OperationalReportStatus::Verified)
                            <div class="mt-4 flex flex-wrap gap-2">
                                <a href="{{ route('preparation.sessions.calculation-pdf', $selected) }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 hover:bg-slate-50">Unduh Laporan Perhitungan</a>
                                @if($selected->wasteHandoverReport)
                                    <a href="{{ route('v3.waste-handovers.pdf', $selected->wasteHandoverReport) }}" target="_blank" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 hover:bg-slate-50">Unduh Berita Acara Limbah</a>
                                @endif
                            </div>
                        @elseif($canExport && $selected->state === 'completed')
                            <p class="mt-3 text-xs font-semibold text-slate-500">Ekspor laporan tersedia setelah disetujui Kepala SPPG.</p>
                        @endif
                        @if($selected->review_notes) <p class="mt-3 rounded-xl bg-amber-50 p-3 text-xs text-amber-800">Catatan pemeriksa: {{ $selected->review_notes }}</p> @endif
                        @if($canEdit && $selected->state === 'planned') <button wire:click="start" class="mt-4 rounded-xl bg-sky-600 px-4 py-2 text-xs font-bold text-white">Mulai Persiapan</button> @endif
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h3 class="font-bold">Bahan Persiapan</h3>
                        <p class="mt-1 text-xs text-slate-500">Hasil bersih + limbah + retur terverifikasi harus sama dengan jumlah yang diterima.</p>
                        <div class="mt-4 space-y-3">
                            @foreach($selected->items as $item)
                                <div class="rounded-xl bg-slate-50 p-4">
                                    <div class="flex justify-between gap-3"><b>{{ $item->ingredient_name_snapshot }}</b><span class="text-xs">Diterima {{ number_format((float) ($item->received_quantity ?? $item->received_weight_kg), 3, ',', '.') }} {{ $item->unit_snapshot }}</span></div>
                                    <div class="mt-3 grid gap-3 md:grid-cols-4">
                                        <label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Hasil bersih</span><input wire:model="items.{{ $item->id }}.processed_quantity" @disabled(!$canEdit || $selected->state !== 'in_progress') type="number" min="0" step=".001" class="h-10 w-full rounded-lg border px-3 text-sm" placeholder="0 {{ $item->unit_snapshot }}"></label>
                                        <label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Limbah/sisa</span><input wire:model="items.{{ $item->id }}.waste_quantity" @disabled(!$canEdit || $selected->state !== 'in_progress') type="number" min="0" step=".001" class="h-10 w-full rounded-lg border px-3 text-sm" placeholder="0 {{ $item->unit_snapshot }}"></label>
                                        <div><span class="mb-1 block text-[11px] font-semibold text-slate-500">Dikembalikan ke Gudang</span><div class="flex h-10 items-center rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700">{{ number_format((float) $item->returns->where('status', \App\Models\PreparationReturn::VERIFIED)->sum('actual_quantity'), 3, ',', '.') }} {{ $item->unit_snapshot }}</div></div>
                                        <label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Catatan</span><input wire:model="items.{{ $item->id }}.notes" @disabled(!$canEdit || $selected->state !== 'in_progress') class="h-10 w-full rounded-lg border px-3 text-sm" placeholder="Opsional"></label>
                                    </div>
                                    @if($item->returns->isNotEmpty())
                                        <div class="mt-3 space-y-2">
                                            @foreach($item->returns as $return)
                                                <div class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs">
                                                    <div><b>Retur {{ number_format((float) $return->requested_quantity, 3, ',', '.') }} {{ $return->unit_snapshot }}</b><br><span class="text-slate-600">{{ $return->reason }}</span></div>
                                                    <div class="text-right"><span class="font-bold {{ $return->status === \App\Models\PreparationReturn::VERIFIED ? 'text-emerald-700' : ($return->status === \App\Models\PreparationReturn::REJECTED ? 'text-rose-700' : 'text-amber-700') }}">{{ str($return->status)->replace('_', ' ')->title() }}</span>@if($return->actual_quantity !== null)<br>Aktual {{ number_format((float) $return->actual_quantity, 3, ',', '.') }} {{ $return->unit_snapshot }}@endif</div>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                    @if($canEdit && $selected->state === 'in_progress')
                                        <details class="mt-3 rounded-xl border border-slate-200 bg-white p-3">
                                            <summary class="cursor-pointer text-xs font-bold text-amber-700">Ada bahan yang dikembalikan ke Gudang</summary>
                                            <div class="mt-3 grid gap-2 md:grid-cols-2">
                                                <input wire:model="returnQuantities.{{ $item->id }}" type="number" min="0" step=".001" class="rounded-lg border p-2 text-sm" placeholder="Jumlah retur ({{ $item->unit_snapshot }})">
                                                <input wire:model="returnReasons.{{ $item->id }}" class="rounded-lg border p-2 text-sm" placeholder="Catatan retur (opsional)">
                                                <button wire:click="submitReturn({{ $item->id }})" class="rounded-lg bg-amber-500 px-3 py-2 text-xs font-bold text-white md:col-span-2">Kembalikan ke Gudang</button>
                                            </div>
                                        </details>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @if($canEdit && $selected->state === 'in_progress') <textarea wire:model="notes" class="mt-3 w-full rounded-xl border p-3 text-sm" placeholder="Catatan sesi"></textarea><button wire:click="save" class="mt-3 rounded-xl border px-4 py-2 text-xs font-bold">Simpan bahan</button> @endif
                    </div>

                    @php($totalPreparationWaste = $selected->items->sum(fn($item) => (float) ($item->waste_quantity ?? $item->waste_weight_kg)))
                    @if($totalPreparationWaste > 0)
                        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                            <h3 class="font-bold text-amber-900">Berita Acara Serah Terima Limbah</h3>
                            <p class="mt-1 text-xs text-amber-700">Menggunakan satu format bersama untuk Persiapan, Pencucian, dan Kebersihan.</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @if($selected->wasteHandoverReport)
                                    <a wire:navigate href="{{ route('v3.waste-handovers.show', $selected->wasteHandoverReport) }}" class="rounded-xl bg-amber-600 px-4 py-2 text-xs font-bold text-white">Buka berita acara</a>
                                    <a target="_blank" href="{{ route('v3.waste-handovers.pdf', $selected->wasteHandoverReport) }}" class="rounded-xl border border-amber-300 bg-white px-4 py-2 text-xs font-bold text-amber-800">Unduh PDF</a>
                                @elseif($canEdit)
                                    <a wire:navigate href="{{ route('v3.waste-handovers.create', ['division'=>'preparation','source_type'=>'preparation_session','source_id'=>$selected->id,'source_reference'=>$selected->session_number]) }}" class="rounded-xl bg-amber-600 px-4 py-2 text-xs font-bold text-white">Buat berita acara limbah</a>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h3 class="font-bold">Foto hasil Persiapan</h3>
                        <p class="mt-1 text-xs text-slate-500">Cukup satu foto yang memperlihatkan keseluruhan hasil Persiapan.</p>
                        @if($selected->resultDocumentation)
                            <x-v3.documentation-button
                                :url="Storage::disk('public')->url($selected->resultDocumentation->photo_path)"
                                :title="'Hasil Persiapan · '.$selected->session_number"
                                label="Lihat foto hasil"
                                class="mt-3"
                            />
                        @endif
                        @if($canEdit && in_array($selected->state, ['in_progress', 'completed'], true))
                            <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                                <input wire:model="documentationPhoto" type="file" accept="image/*" capture="environment" class="min-w-0 flex-1 text-xs">
                                <button wire:click="uploadDocumentation" class="rounded-lg bg-sky-600 px-4 py-2 text-xs font-bold text-white">{{ $selected->resultDocumentation ? 'Ganti foto hasil' : 'Simpan foto hasil' }}</button>
                            </div>
                        @endif
                    </div>

                    @if($canEdit && $selected->state === 'in_progress') <div class="flex justify-end"><button wire:click="complete" class="rounded-xl bg-emerald-600 px-5 py-3 text-xs font-bold text-white">Selesaikan Persiapan</button></div> @endif

                    @if($canSubmit && $selected->state === 'completed' && in_array($selected->status, [\App\Enums\OperationalReportStatus::Draft, \App\Enums\OperationalReportStatus::RevisionRequired], true)) <div class="flex justify-end"><button wire:click="submit" class="rounded-xl bg-sky-700 px-5 py-3 text-xs font-bold text-white">Ajukan laporan Persiapan</button></div> @endif

                    @if($canApprove && in_array($selected->status, [\App\Enums\OperationalReportStatus::Submitted, \App\Enums\OperationalReportStatus::DivisionApproved], true))
                        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5"><h3 class="font-bold">Pemeriksaan laporan</h3><textarea wire:model="reviewNotes" class="mt-3 w-full rounded-xl border p-3 text-sm" placeholder="Catatan pemeriksa"></textarea><div class="mt-3 flex justify-end gap-2"><button wire:click="requestRevision" class="rounded-xl px-4 py-2 text-xs font-bold text-rose-700">Minta revisi</button><button wire:click="approve" class="rounded-xl bg-sky-700 px-4 py-2 text-xs font-bold text-white">Setujui tahap ini</button></div></div>
                    @endif
                </section>
            @endif
        </div>
    </div>
</x-v3.shell>
