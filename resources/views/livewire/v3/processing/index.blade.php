<x-v3.shell :$unit :$navigation :$roleLabel title="Divisi Pengolahan" eyebrow="Bahan Gudang sampai makanan selesai dimasak">
    <div class="mx-auto max-w-[1500px] space-y-5">
        @if(session('v3.status'))
            <div class="rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('v3.status') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>
        @endif

        <section class="rounded-[28px] bg-[#081d3a] p-6 text-white">
            <p class="text-xs font-bold uppercase tracking-widest text-cyan-200">Kontrol Pengolahan</p>
            <h2 class="mt-2 text-2xl font-bold">Catat makanan matang dan tekan Selesai.</h2>
            <p class="mt-2 max-w-3xl text-sm text-slate-300">Bahan muncul langsung setelah diambil dari Gudang. Catat hasil akhir, suhu setelah matang, dan satu foto untuk setiap makanan. Setelah selesai, hasil otomatis tersedia di Pemorsian tanpa serah-terima.</p>
        </section>

        <div class="grid gap-5 xl:grid-cols-[350px_minmax(0,1fr)]">
            <section class="space-y-2">
                @forelse($records as $record)
                    <button wire:click="select({{ $record->id }})" class="w-full rounded-2xl border p-4 text-left {{ $selectedId === $record->id ? 'border-sky-400 bg-sky-50' : 'border-slate-200 bg-white' }}">
                        <div class="flex justify-between gap-2">
                            <b>{{ $record->batch_number }}</b>
                            <span class="text-[10px] font-bold uppercase">{{ $record->state->label() }}</span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ $record->menu_name_snapshot }} · {{ $record->production_date?->translatedFormat('d M Y') }}</p>
                        <p class="mt-2 text-[10px] font-bold text-sky-700">{{ $statusLabels[$record->status->value] ?? $record->status->value }}</p>
                    </button>
                @empty
                    <div class="rounded-2xl bg-white p-8 text-center text-sm text-slate-500">Belum ada rencana Pengolahan. Batch akan muncul dari rencana lapangan yang sudah aktif.</div>
                @endforelse
            </section>

            @if($selected)
                <section class="space-y-5">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex flex-wrap justify-between gap-3">
                            <div>
                                <h2 class="text-xl font-bold">{{ $selected->batch_number }}</h2>
                                <p class="text-sm text-slate-500">{{ $selected->menu_name_snapshot }} · target {{ number_format((float) $selected->target_output_quantity, 3, ',', '.') }} {{ $selected->target_output_unit }}</p>
                            </div>
                            <div class="text-right">
                                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold">{{ $selected->state->label() }}</span>
                                <p class="mt-2 text-xs font-bold text-sky-700">{{ $statusLabels[$selected->status->value] ?? $selected->status->value }}</p>
                            </div>
                        </div>
                        @if($selected->review_notes)
                            <p class="mt-3 rounded-xl bg-amber-50 p-3 text-xs text-amber-800">Catatan pemeriksa: {{ $selected->review_notes }}</p>
                        @endif
                        @if($canExport && $selected->status === \App\Enums\OperationalReportStatus::Verified)
                            <div class="mt-4 flex flex-wrap gap-2">
                                <a href="{{ route('processing-batches.production-pdf', $selected) }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-700">Unduh Monitoring Produksi</a>
                                <a href="{{ route('processing-batches.temperature-pdf', $selected) }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-700">Unduh Pemantauan Suhu</a>
                            </div>
                        @elseif($canExport && $selected->state === \App\Enums\ProcessingBatchState::Completed)
                            <p class="mt-3 text-xs font-semibold text-slate-500">Ekspor tersedia setelah laporan disetujui Kepala SPPG.</p>
                        @endif
                        @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::Planned)
                            <button wire:click="start" class="mt-4 rounded-xl bg-sky-600 px-4 py-2 text-xs font-bold text-white">Mulai Pengolahan</button>
                        @endif
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h3 class="font-bold">Bahan dari Gudang</h3>
                        <p class="mt-1 text-xs text-slate-500">Bahan dapat langsung digunakan. Verifikasi Gudang berjalan terpisah untuk menyesuaikan stok sistem.</p>
                        <div class="mt-4 space-y-3">
                            @forelse($selected->materialUsages as $usage)
                                @php($verifiedReturn = $usage->returns->where('status', \App\Models\ProcessingReturn::VERIFIED)->sum('actual_quantity'))
                                <div class="rounded-xl bg-slate-50 p-4">
                                    <div class="flex flex-wrap justify-between gap-3">
                                        <div><b>{{ $usage->material_name }}</b><p class="text-xs text-slate-500">{{ $usage->source_reference ?: 'Pengambilan Gudang' }}</p></div>
                                        <div class="text-right text-sm font-semibold">{{ number_format((float) $usage->quantity, 3, ',', '.') }} {{ $usage->unit_name }}@if($verifiedReturn)<p class="text-xs text-amber-700">Retur {{ number_format((float) $verifiedReturn, 3, ',', '.') }} {{ $usage->unit_name }}</p>@endif</div>
                                    </div>
                                    @foreach($usage->returns as $return)
                                        <div class="mt-2 flex flex-wrap justify-between gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs">
                                            <span>Retur {{ number_format((float) $return->requested_quantity, 3, ',', '.') }} {{ $return->unit_snapshot }} · {{ $return->reason }}</span>
                                            <b>{{ str($return->status)->replace('_', ' ')->title() }}</b>
                                        </div>
                                    @endforeach
                                    @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                                        <details class="mt-3 rounded-xl border border-slate-200 bg-white p-3">
                                            <summary class="cursor-pointer text-xs font-bold text-amber-700">Ada bahan yang dikembalikan ke Gudang</summary>
                                            <div class="mt-3 grid gap-2 md:grid-cols-2">
                                                <input wire:model="returnQuantities.{{ $usage->id }}" type="number" min="0" step=".001" class="rounded-lg border p-2 text-sm" placeholder="Jumlah retur ({{ $usage->unit_name }})">
                                                <input wire:model="returnReasons.{{ $usage->id }}" class="rounded-lg border p-2 text-sm" placeholder="Catatan retur (opsional)">
                                                <button wire:click="submitReturn({{ $usage->id }})" class="rounded-lg bg-amber-500 px-3 py-2 text-xs font-bold text-white md:col-span-2">Kembalikan ke Gudang</button>
                                            </div>
                                        </details>
                                    @endif
                                </div>
                            @empty
                                <div class="rounded-xl border border-dashed p-6 text-center text-sm text-slate-500">Belum ada bahan yang diambil dari Gudang.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div><h3 class="font-bold">Makanan matang</h3><p class="mt-1 text-xs text-slate-500">Satu baris untuk setiap makanan yang selesai dimasak.</p></div>
                            @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                                <button wire:click="addCookedProduct" class="rounded-lg border border-sky-200 px-3 py-2 text-xs font-bold text-sky-700">+ Tambah makanan</button>
                            @endif
                        </div>
                        <div class="mt-4 space-y-3">
                            @foreach($cookedProducts as $index => $product)
                                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4" wire:key="cooked-product-{{ $index }}">
                                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                                        <label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Nama makanan</span><input wire:model="cookedProducts.{{ $index }}.product_name" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) class="h-10 w-full rounded-lg border px-3 text-sm" placeholder="Contoh: Ayam bumbu kuning"></label>
                                        <label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Suhu setelah matang (°C)</span><input wire:model="cookedProducts.{{ $index }}.temperature_celsius" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) type="number" step=".1" class="h-10 w-full rounded-lg border px-3 text-sm"></label>
                                        <label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Waktu matang</span><input wire:model="cookedProducts.{{ $index }}.cooked_at" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) type="datetime-local" class="h-10 w-full rounded-lg border px-3 text-sm"></label>
                                        <label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Catatan</span><input wire:model="cookedProducts.{{ $index }}.notes" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) class="h-10 w-full rounded-lg border px-3 text-sm" placeholder="Opsional"></label>
                                    </div>
                                    <div class="mt-3 flex flex-wrap items-center gap-2">
                                        @if($product['photo_path'] ?? null)
                                            <x-v3.documentation-button :url="Storage::disk('public')->url($product['photo_path'])" :title="'Hasil Pengolahan · '.($product['product_name'] ?: 'Makanan matang')" label="Lihat foto hasil" />
                                        @endif
                                        @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                                            <label class="inline-flex h-10 cursor-pointer items-center rounded-xl bg-sky-600 px-4 text-xs font-bold text-white">
                                                <span>{{ ($product['photo_path'] ?? null) ? 'Ganti foto' : 'Pilih foto' }}</span>
                                                <input wire:model="cookedPhotos.{{ $index }}" type="file" accept="image/*" capture="environment" class="sr-only">
                                            </label>
                                            @if(($cookedPhotos[$index] ?? null) instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                                <span class="max-w-xs truncate text-xs text-slate-500">{{ $cookedPhotos[$index]->getClientOriginalName() }}</span>
                                            @endif
                                            <button wire:click="removeCookedProduct({{ $index }})" class="ml-auto text-xs font-bold text-rose-600">Hapus makanan</button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                            @if($cookedProducts === [])
                                <div class="rounded-xl border border-dashed p-6 text-center text-sm text-slate-500">Belum ada makanan matang yang dicatat.</div>
                            @endif
                        </div>

                        <div class="mt-5 grid gap-3 md:grid-cols-[1fr_220px]">
                            <label><span class="mb-1 block text-xs font-semibold text-slate-600">Jumlah hasil akhir</span><input wire:model="actualOutputQuantity" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) type="number" min="0" step=".001" class="h-11 w-full rounded-xl border px-3 text-sm"></label>
                            <label><span class="mb-1 block text-xs font-semibold text-slate-600">Satuan</span><input wire:model="actualOutputUnit" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) class="h-11 w-full rounded-xl border px-3 text-sm" placeholder="porsi, kg, loyang, dll."></label>
                        </div>
                        <textarea wire:model="notes" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) class="mt-3 w-full rounded-xl border p-3 text-sm" placeholder="Catatan Pengolahan (opsional)"></textarea>
                        @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                            <button wire:click="save" class="mt-3 rounded-xl border px-4 py-2 text-xs font-bold">Simpan data</button>
                        @endif
                    </div>

                    @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                        <div class="flex justify-end"><button wire:click="complete" wire:confirm="Selesaikan Pengolahan dan tampilkan hasil di Pemorsian?" class="rounded-xl bg-emerald-600 px-5 py-3 text-xs font-bold text-white">Selesaikan Pengolahan</button></div>
                    @endif

                    @if($canSubmit && $selected->state === \App\Enums\ProcessingBatchState::Completed && in_array($selected->status, [\App\Enums\OperationalReportStatus::Draft, \App\Enums\OperationalReportStatus::RevisionRequired], true))
                        <div class="flex justify-end"><button wire:click="submit" class="rounded-xl bg-sky-700 px-5 py-3 text-xs font-bold text-white">Ajukan laporan Pengolahan</button></div>
                    @endif

                    @if($canApprove && in_array($selected->status, [\App\Enums\OperationalReportStatus::Submitted, \App\Enums\OperationalReportStatus::DivisionApproved], true))
                        <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5">
                            <h3 class="font-bold">Pemeriksaan laporan</h3>
                            <textarea wire:model="reviewNotes" class="mt-3 w-full rounded-xl border p-3 text-sm" placeholder="Catatan pemeriksa"></textarea>
                            <div class="mt-3 flex justify-end gap-2">
                                <button wire:click="requestRevision" class="rounded-xl px-4 py-2 text-xs font-bold text-rose-700">Minta revisi</button>
                                <button wire:click="approve" class="rounded-xl bg-sky-700 px-4 py-2 text-xs font-bold text-white">Setujui tahap ini</button>
                            </div>
                        </div>
                    @endif
                </section>
            @endif
        </div>
    </div>
</x-v3.shell>
