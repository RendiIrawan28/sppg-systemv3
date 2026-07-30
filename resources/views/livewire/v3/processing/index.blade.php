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
            <p class="mt-2 max-w-3xl text-sm text-slate-300">Bahan muncul langsung setelah diambil dari Gudang. Lengkapi dokumentasi suhu serta dokumentasi berat atau jumlah makanan jadi.</p>
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
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div><h3 class="font-bold">Hasil dari Divisi Persiapan</h3><p class="mt-1 text-xs text-slate-500">Bumbu dan bahan siap pakai yang telah diambil untuk batch ini.</p></div>
                            <a wire:navigate href="{{ route('v3.preparation-outputs.index') }}" class="rounded-xl border border-sky-200 px-4 py-2 text-xs font-bold text-sky-700">Ambil hasil Persiapan</a>
                        </div>
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            @forelse($selected->preparationOutputWithdrawals->where('status', \App\Models\PreparationOutputWithdrawal::VERIFIED) as $withdrawal)
                                <div class="rounded-xl bg-slate-50 p-4"><b>{{ $withdrawal->output?->output_name }}</b><p class="mt-1 text-xs text-slate-500">{{ number_format((float) $withdrawal->verified_quantity, 3, ',', '.') }} {{ $withdrawal->unit_snapshot }} · {{ $withdrawal->output?->storage_location ?: 'Lokasi tidak dicatat' }}</p></div>
                            @empty
                                <div class="rounded-xl border border-dashed p-5 text-sm text-slate-500 md:col-span-2">Belum ada hasil Persiapan yang diverifikasi untuk batch ini.</div>
                            @endforelse
                        </div>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div><h3 class="font-bold">1. Dokumentasi suhu makanan</h3><p class="mt-1 text-xs text-slate-500">Catat suhu setelah matang dan lampirkan foto pengukurannya untuk setiap makanan.</p></div>
                            @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                                <button wire:click="addCookedProduct" class="rounded-lg border border-sky-200 px-3 py-2 text-xs font-bold text-sky-700">+ Tambah makanan</button>
                            @endif
                        </div>
                        <div class="mt-4 space-y-3">
                            @foreach($cookedProducts as $index => $product)
                                <div class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5" wire:key="cooked-product-{{ $index }}">
                                    <div class="mb-4 flex items-center justify-between gap-3">
                                        <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Makanan {{ $index + 1 }}</p>
                                        @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                                            <button type="button" wire:click="removeCookedProduct({{ $index }})" class="rounded-lg px-3 py-2 text-xs font-bold text-rose-600 hover:bg-rose-50">Hapus makanan</button>
                                        @endif
                                    </div>

                                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                                        <label><span class="mb-1 block text-xs font-semibold text-slate-600">Nama makanan</span><input wire:model="cookedProducts.{{ $index }}.product_name" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Contoh: Ayam bumbu kuning"></label>
                                        <label><span class="mb-1 block text-xs font-semibold text-slate-600">Suhu setelah matang (°C)</span><input wire:model="cookedProducts.{{ $index }}.temperature_celsius" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) type="number" step=".1" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Contoh: 75"></label>
                                        <label><span class="mb-1 block text-xs font-semibold text-slate-600">Waktu matang</span><input wire:model="cookedProducts.{{ $index }}.cooked_at" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) type="datetime-local" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></label>
                                        <label><span class="mb-1 block text-xs font-semibold text-slate-600">Catatan makanan</span><input wire:model="cookedProducts.{{ $index }}.notes" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Opsional"></label>
                                    </div>

                                    <div class="mt-4 rounded-xl border border-slate-200 bg-white p-3">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p class="text-xs font-semibold text-slate-700">Foto pengukuran suhu <span class="text-rose-500">*</span></p>
                                                <p class="mt-0.5 text-[11px] text-slate-500">Foto termometer saat mengukur makanan ini, maksimal 5 MB.</p>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                @if($product['temperature_photo_path'] ?? null)
                                                    <x-v3.documentation-button :url="Storage::disk('public')->url($product['temperature_photo_path'])" :title="'Dokumentasi suhu · '.($product['product_name'] ?: 'Makanan matang')" label="Lihat foto suhu" />
                                                @endif
                                                @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                                                    <input id="temperature-photo-{{ $index }}" wire:model="temperaturePhotos.{{ $index }}" type="file" accept="image/*" capture="environment" class="hidden" style="display: none;">
                                                    <label for="temperature-photo-{{ $index }}" class="inline-flex h-10 cursor-pointer items-center rounded-xl bg-sky-600 px-4 text-xs font-bold text-white hover:bg-sky-700">
                                                        {{ ($product['temperature_photo_path'] ?? null) ? 'Ganti foto suhu' : 'Pilih foto suhu' }}
                                                    </label>
                                                @endif
                                            </div>
                                        </div>
                                        @if(($temperaturePhotos[$index] ?? null) instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                            <p class="mt-2 truncate rounded-lg bg-sky-50 px-3 py-2 text-xs font-semibold text-sky-700">Foto suhu dipilih: {{ $temperaturePhotos[$index]->getClientOriginalName() }}</p>
                                        @endif
                                        <p wire:loading wire:target="temperaturePhotos.{{ $index }}" class="mt-2 text-xs font-semibold text-sky-600">Mengunggah foto suhu...</p>
                                        @error("temperaturePhotos.$index") <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            @endforeach
                            @if($cookedProducts === [])
                                <div class="rounded-xl border border-dashed p-6 text-center text-sm text-slate-500">Belum ada makanan matang yang dicatat.</div>
                            @endif
                        </div>

                        <div class="mt-5 rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4 sm:p-5">
                            <div class="flex flex-wrap items-end justify-between gap-3">
                                <div>
                                    <h3 class="font-bold text-slate-900">2. Berat/jumlah dan dokumentasi makanan jadi</h3>
                                    <p class="mt-1 text-xs text-slate-500">Tambahkan setiap data hasil makanan jadi beserta berat/jumlah, satuan, dan fotonya.</p>
                                </div>
                                @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                                    <button type="button" wire:click="addFinishedOutputDocumentation" class="rounded-lg border border-emerald-200 bg-white px-3 py-2 text-xs font-bold text-emerald-700 hover:bg-emerald-50">
                                        + Tambah data hasil
                                    </button>
                                @endif
                            </div>

                            <div class="mt-4 space-y-3">
                                @foreach($finishedOutputDocumentations as $index => $documentation)
                                    <div class="rounded-2xl border border-emerald-200 bg-white p-4" wire:key="finished-output-documentation-{{ $documentation['documentation_id'] ?? 'new' }}-{{ $index }}">
                                        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                                            <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">Data hasil makanan jadi {{ $index + 1 }}</p>
                                            @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress && count($finishedOutputDocumentations) > 1)
                                                <button type="button" wire:click="removeFinishedOutputDocumentation({{ $index }})" class="rounded-lg px-3 py-2 text-xs font-bold text-rose-600 hover:bg-rose-50">
                                                    Hapus data hasil
                                                </button>
                                            @endif
                                        </div>

                                        <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_180px]">
                                            <label>
                                                <span class="mb-1 block text-xs font-semibold text-slate-600">Berat/jumlah hasil <span class="text-rose-500">*</span></span>
                                                <input wire:model="finishedOutputDocumentations.{{ $index }}.output_quantity" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) type="number" min="0" step=".001" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Contoh: 25">
                                                @error("finishedOutputDocumentations.$index.output_quantity") <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                            </label>
                                            <label>
                                                <span class="mb-1 block text-xs font-semibold text-slate-600">Satuan <span class="text-rose-500">*</span></span>
                                                <input wire:model="finishedOutputDocumentations.{{ $index }}.output_unit" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="kg, loyang, pcs, porsi">
                                                @error("finishedOutputDocumentations.$index.output_unit") <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                            </label>
                                        </div>

                                        <div class="mt-4 grid gap-4 md:grid-cols-[minmax(0,1fr)_220px]">
                                            <label>
                                                <span class="mb-1 block text-xs font-semibold text-slate-600">Nama/keterangan hasil</span>
                                                <input wire:model="finishedOutputDocumentations.{{ $index }}.caption" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Contoh: Nasi matang, ayam matang, sayur">
                                                @error("finishedOutputDocumentations.$index.caption") <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                            </label>
                                            <label>
                                                <span class="mb-1 block text-xs font-semibold text-slate-600">Waktu dokumentasi</span>
                                                <input wire:model="finishedOutputDocumentations.{{ $index }}.captured_at" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) type="datetime-local" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                                                @error("finishedOutputDocumentations.$index.captured_at") <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                            </label>
                                        </div>

                                        <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50/40 p-3">
                                            <div class="flex flex-wrap items-center justify-between gap-3">
                                                <div>
                                                    <p class="text-xs font-semibold text-slate-700">Foto berat/jumlah hasil ini <span class="text-rose-500">*</span></p>
                                                    <p class="mt-0.5 text-[11px] text-slate-500">Foto harus sesuai dengan berat/jumlah dan satuan pada baris ini, maksimal 5 MB.</p>
                                                </div>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    @if($documentation['photo_path'] ?? null)
                                                        <x-v3.documentation-button :url="Storage::disk('public')->url($documentation['photo_path'])" :title="'Dokumentasi hasil · '.(($documentation['caption'] ?? null) ?: 'Makanan jadi')" label="Lihat foto hasil" />
                                                    @endif
                                                    @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                                                        <input id="finished-output-photo-{{ $index }}" wire:model="finishedOutputPhotos.{{ $index }}" type="file" accept="image/*" capture="environment" class="hidden" style="display: none;">
                                                        <label for="finished-output-photo-{{ $index }}" class="inline-flex h-10 cursor-pointer items-center rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white hover:bg-emerald-700">
                                                            {{ ($documentation['photo_path'] ?? null) ? 'Ganti foto hasil' : 'Pilih foto hasil' }}
                                                        </label>
                                                    @endif
                                                </div>
                                            </div>
                                            @if(($finishedOutputPhotos[$index] ?? null) instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                                <p class="mt-2 truncate rounded-lg bg-emerald-100 px-3 py-2 text-xs font-semibold text-emerald-800">Foto hasil dipilih: {{ $finishedOutputPhotos[$index]->getClientOriginalName() }}</p>
                                            @endif
                                            <p wire:loading wire:target="finishedOutputPhotos.{{ $index }}" class="mt-2 text-xs font-semibold text-emerald-700">Mengunggah foto hasil...</p>
                                            @error("finishedOutputPhotos.$index") <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                        </div>
                                    </div>
                                @endforeach

                                @if($finishedOutputDocumentations === [])
                                    <div class="rounded-xl border border-dashed border-emerald-300 p-6 text-center text-sm text-slate-500">Belum ada data berat/jumlah makanan jadi.</div>
                                @endif
                            </div>
                        </div>
                        <label class="mt-4 block">
                            <span class="mb-1 block text-xs font-semibold text-slate-600">Catatan Pengolahan</span>
                            <textarea wire:model="notes" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) rows="3" class="w-full rounded-xl border p-3 text-sm" placeholder="Opsional"></textarea>
                        </label>
                        @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                            <div class="mt-5 flex flex-col gap-3 border-t border-slate-200 pt-4">
                                <p class="text-xs text-slate-500">Simpan sementara jika data belum lengkap, atau selesaikan setelah seluruh hasil dicatat.</p>
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button type="button" wire:click="save" class="h-11 rounded-xl border border-slate-300 bg-white px-5 text-xs font-bold text-slate-700 hover:bg-slate-50">Simpan sementara</button>
                                    <button type="button" wire:click="complete" wire:confirm="Selesaikan seluruh pekerjaan Pengolahan?" class="h-11 rounded-xl bg-emerald-600 px-5 text-xs font-bold text-white hover:bg-emerald-700">Selesaikan Pengolahan</button>
                                </div>
                            </div>
                        @endif
                    </div>

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
