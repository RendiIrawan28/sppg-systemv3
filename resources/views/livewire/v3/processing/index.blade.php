<x-v3.shell :$unit :$navigation :$roleLabel title="Divisi Pengolahan" eyebrow="Monitoring Produksi per batch">
    <div class="mx-auto max-w-[1500px] space-y-5">
        <x-v3.flash-alert />

        <section class="rounded-[28px] bg-[#081d3a] p-6 text-white">
            <p class="text-xs font-bold uppercase tracking-widest text-cyan-200">Pengolahan</p>
            <h2 class="mt-2 text-2xl font-bold">Satu batch, satu monitoring produksi.</h2>
            <p class="mt-2 max-w-3xl text-sm text-slate-300">
                Catat bahan baku aktual, minimal satu suhu pangan matang dengan foto, hasil akhir, dan satu dokumentasi batch.
            </p>
        </section>

        @if($canEdit)
            <section class="rounded-2xl border border-sky-200 bg-white p-5 shadow-sm">
                <div class="grid items-end gap-3 md:grid-cols-[220px_minmax(0,1fr)_auto]">
                    <label>
                        <span class="mb-1 block text-xs font-bold uppercase tracking-wide text-sky-700">Tanggal produksi</span>
                        <input wire:model="newProductionDate" type="date" class="h-12 w-full rounded-xl border border-slate-200 px-3 text-sm">
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-bold uppercase tracking-wide text-sky-700">Nama produk/menu</span>
                        <input wire:model="newProductName" class="h-12 w-full rounded-xl border border-slate-200 px-3 text-sm" placeholder="Contoh: Ayam bumbu kuning">
                    </label>
                    <button wire:click="createManualBatch" class="h-12 rounded-xl bg-sky-600 px-5 text-xs font-bold text-white">Buat & mulai batch</button>
                </div>
                @error('newProductionDate') <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                @error('newProductName') <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
            </section>
        @endif

        <div class="grid gap-5 xl:grid-cols-[350px_minmax(0,1fr)]">
            <section class="space-y-2">
                @forelse($records as $record)
                    <button wire:click="select({{ $record->id }})" class="w-full rounded-2xl border p-4 text-left {{ $selectedId === $record->id ? 'border-sky-400 bg-sky-50' : 'border-slate-200 bg-white' }}">
                        <div class="flex justify-between gap-2">
                            <b>{{ $record->batch_number }}</b>
                            <span class="text-[10px] font-bold uppercase">
                                {{ $record->portioning_received_at ? 'Diterima Pemorsian' : ($record->portioning_handed_over_at ? 'Siap Diporsikan' : $record->state->label()) }}
                            </span>
                        </div>
                        <p class="mt-1 text-xs text-slate-500">{{ $record->product_name ?: $record->menu_name_snapshot }} · {{ $record->production_date?->translatedFormat('d M Y') }}</p>
                        <p class="mt-2 text-[10px] font-bold text-sky-700">{{ $statusLabels[$record->status->value] ?? $record->status->value }}</p>
                    </button>
                @empty
                    <div class="rounded-2xl bg-white p-8 text-center text-sm text-slate-500">Belum ada batch Pengolahan.</div>
                @endforelse
            </section>

            @if($selected)
                <section class="space-y-5">
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex flex-wrap justify-between gap-3">
                            <div>
                                <h2 class="text-xl font-bold">{{ $selected->batch_number }}</h2>
                                <p class="mt-1 text-sm text-slate-500">{{ $selected->product_name ?: $selected->menu_name_snapshot }}</p>
                                <p class="mt-1 text-xs text-slate-400">
                                    Mulai {{ $selected->started_at?->format('H:i') ?: '-' }}
                                    @if($selected->duration_minutes !== null) · {{ $selected->duration_minutes }} menit @endif
                                </p>
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
                            <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <p class="text-xs font-bold text-slate-700">Ekspor laporan harian {{ $selected->production_date?->format('d-m-Y') }}</p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <a href="{{ route('processing-batches.production-pdf', $selected) }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700">Monitoring Produksi Harian</a>
                                    <a href="{{ route('processing-batches.temperature-pdf', $selected) }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700">Pemantauan Suhu Harian</a>
                                </div>
                                <p class="mt-2 text-[11px] text-slate-500">PDF menggabungkan seluruh batch pada tanggal produksi yang sama.</p>
                            </div>
                        @elseif($canExport && $selected->state === \App\Enums\ProcessingBatchState::Completed)
                            <p class="mt-3 text-xs font-semibold text-slate-500">Ekspor harian tersedia setelah seluruh batch tanggal ini disetujui Kepala SPPG.</p>
                        @endif

                        @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::Planned)
                            <button wire:click="start" class="mt-4 rounded-xl bg-sky-600 px-4 py-2 text-xs font-bold text-white">Mulai Pengolahan</button>
                        @endif

                        @if($canEdit && in_array($selected->id, $cancellableBatchIds, true))
                            <div class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-3">
                                <label class="block text-xs font-semibold text-rose-800">Alasan pembatalan</label>
                                <textarea wire:model="cancellationReason" rows="2" class="mt-2 w-full rounded-lg border border-rose-200 bg-white p-2 text-sm" placeholder="Wajib diisi"></textarea>
                                @error('cancellationReason') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                <button wire:click="cancel" wire:confirm="Batalkan produksi ini?" class="mt-2 rounded-lg px-3 py-2 text-xs font-bold text-rose-700">Batalkan produksi</button>
                            </div>
                        @endif
                    </div>

                    @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                        <div class="grid gap-3 sm:grid-cols-2">
                            <a href="{{ route('v3.warehouse.withdrawals.index') }}" class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-center text-sm font-bold text-sky-700">Ambil bahan dari Gudang</a>
                            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-center text-sm font-bold text-emerald-700">Hasil Persiapan yang diserahkan akan muncul otomatis</div>
                        </div>
                    @endif

                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h3 class="font-bold">1. Bahan Baku Aktual</h3>
                                <p class="mt-1 text-xs text-slate-500">Tetap dicatat manual sesuai bahan yang benar-benar digunakan. Catatan ini tidak mengurangi stok lagi.</p>
                            </div>
                            @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                                <button wire:click="addManualMaterial" class="rounded-lg border px-3 py-2 text-xs font-bold">+ Tambah bahan</button>
                            @endif
                        </div>
                        <div class="mt-4 space-y-3">
                            @foreach($manualMaterials as $index => $material)
                                <div class="grid gap-3 rounded-xl bg-slate-50 p-3 md:grid-cols-[minmax(0,1fr)_140px_140px_auto]">
                                    <input wire:model="manualMaterials.{{ $index }}.material_name" list="processing-ingredient-suggestions" @disabled($selected->state !== \App\Enums\ProcessingBatchState::InProgress) class="h-10 rounded-lg border px-3 text-sm" placeholder="Nama bahan">
                                    <input wire:model="manualMaterials.{{ $index }}.quantity" @disabled($selected->state !== \App\Enums\ProcessingBatchState::InProgress) type="number" min="0" step=".001" class="h-10 rounded-lg border px-3 text-sm" placeholder="Jumlah">
                                    <input wire:model="manualMaterials.{{ $index }}.unit_name" @disabled($selected->state !== \App\Enums\ProcessingBatchState::InProgress) class="h-10 rounded-lg border px-3 text-sm" placeholder="Satuan">
                                    @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                                        <button wire:click="removeManualMaterial({{ $index }})" class="text-xs font-bold text-rose-600">Hapus</button>
                                    @endif
                                </div>
                                @error("manualMaterials.$index.material_name") <p class="text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                @error("manualMaterials.$index.quantity") <p class="text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                @error("manualMaterials.$index.unit_name") <p class="text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                            @endforeach
                        </div>
                        <datalist id="processing-ingredient-suggestions">
                            @foreach($ingredientSuggestions as $ingredient)
                                <option value="{{ $ingredient->name }}">{{ $ingredient->measurementUnit?->symbol ?: $ingredient->measurementUnit?->code }}</option>
                            @endforeach
                        </datalist>
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h3 class="font-bold">Sumber Bahan Terintegrasi</h3>
                        <p class="mt-1 text-xs text-slate-500">Informasi dari Gudang/Persiapan hanya sebagai jejak sumber. Bahan yang dipakai tetap dicatat pada Bahan Baku Aktual.</p>
                        <div class="mt-4 grid gap-3 lg:grid-cols-2">
                            <div class="rounded-xl bg-slate-50 p-4">
                                <p class="text-xs font-bold uppercase text-slate-500">Dari Gudang</p>
                                <div class="mt-3 space-y-2">
                                    @forelse($selected->materialUsages->where('source_type', '!=', 'manual') as $usage)
                                        <div class="rounded-lg bg-white p-3 text-sm">
                                            <b>{{ $usage->material_name }}</b>
                                            <span class="float-right">{{ number_format((float) $usage->quantity, 3, ',', '.') }} {{ $usage->unit_name }}</span>
                                        </div>
                                    @empty
                                        <p class="text-xs text-slate-500">Belum ada bahan dari Gudang.</p>
                                    @endforelse
                                </div>
                            </div>
                            <div class="rounded-xl bg-emerald-50 p-4">
                                <p class="text-xs font-bold uppercase text-emerald-700">Dari Persiapan</p>
                                <div class="mt-3 space-y-2">
                                    @forelse($selected->preparationOutputWithdrawals->whereIn('status', [\App\Models\PreparationOutputWithdrawal::WAITING, \App\Models\PreparationOutputWithdrawal::VERIFIED]) as $withdrawal)
                                        @php($usedQuantity = $withdrawal->status === \App\Models\PreparationOutputWithdrawal::VERIFIED ? $withdrawal->verified_quantity : $withdrawal->requested_quantity)
                                        <div class="rounded-lg bg-white p-3 text-sm">
                                            <b>{{ $withdrawal->output?->output_name }}</b>
                                            <span class="float-right">{{ number_format((float) $usedQuantity, 3, ',', '.') }} {{ $withdrawal->unit_snapshot }}</span>
                                            @if($withdrawal->status === \App\Models\PreparationOutputWithdrawal::WAITING && $canEdit)
                                                <button wire:click="acceptPreparationOutput({{ $withdrawal->id }})" class="mt-2 block rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white">Terima</button>
                                            @endif
                                        </div>
                                    @empty
                                        <p class="text-xs text-emerald-700">Belum ada hasil Persiapan.</p>
                                    @endforelse
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="rounded-2xl border border-sky-200 bg-sky-50/40 p-5">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="font-bold">2. Pemantauan Suhu Pangan Matang</h3>
                                <p class="mt-1 text-xs text-slate-500">Nama produk, waktu, dan petugas dicatat otomatis. Petugas hanya mengisi suhu dan foto.</p>
                            </div>
                            @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                                <button wire:click="addCookedProduct" class="rounded-lg border border-sky-300 bg-white px-3 py-2 text-xs font-bold text-sky-700">+ Ukur ulang</button>
                            @endif
                        </div>

                        <div class="mt-4 space-y-3">
                            @foreach($cookedProducts as $index => $product)
                                <div class="rounded-2xl border border-sky-200 bg-white p-4" wire:key="temperature-{{ $product['temperature_id'] ?? 'new' }}-{{ $index }}">
                                    <div class="flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-bold">{{ $product['product_name'] ?: ($selected->product_name ?: $selected->menu_name_snapshot) }}</p>
                                            <p class="mt-1 text-xs text-slate-500">Pengukuran {{ $index + 1 }} · {{ \Illuminate\Support\Carbon::parse($product['cooked_at'])->format('d-m-Y H:i') }} · Petugas otomatis</p>
                                        </div>
                                        @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress && count($cookedProducts) > 1)
                                            <button wire:click="removeCookedProduct({{ $index }})" class="text-xs font-bold text-rose-600">Hapus pengukuran</button>
                                        @endif
                                    </div>

                                    <input type="hidden" wire:model="cookedProducts.{{ $index }}.product_name">
                                    <input type="hidden" wire:model="cookedProducts.{{ $index }}.cooked_at">

                                    <div class="mt-4 grid gap-4 md:grid-cols-[220px_minmax(0,1fr)]">
                                        <label>
                                            <span class="mb-1 block text-xs font-semibold text-slate-600">Suhu produk (°C) <span class="text-rose-500">*</span></span>
                                            <input wire:model="cookedProducts.{{ $index }}.temperature_celsius" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) type="number" step=".1" min="-30" max="150" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Contoh: 75.5">
                                            @error("cookedProducts.$index.temperature_celsius") <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                        </label>
                                        <label>
                                            <span class="mb-1 block text-xs font-semibold text-slate-600">Catatan</span>
                                            <input wire:model="cookedProducts.{{ $index }}.notes" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Opsional">
                                        </label>
                                    </div>

                                    <div class="mt-4 rounded-xl border border-sky-200 bg-sky-50 p-3">
                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p class="text-xs font-semibold text-slate-700">Foto pengukuran suhu <span class="text-rose-500">*</span></p>
                                                <p class="mt-0.5 text-[11px] text-slate-500">Satu foto untuk setiap pengukuran suhu.</p>
                                            </div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                @if($product['temperature_photo_path'] ?? null)
                                                    <x-v3.documentation-button :url="Storage::disk('public')->url($product['temperature_photo_path'])" :title="'Dokumentasi suhu · '.($product['product_name'] ?: 'Pangan matang')" label="Lihat foto" />
                                                @endif
                                                @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                                                    <input id="temperature-photo-{{ $index }}" wire:model="temperaturePhotos.{{ $index }}" type="file" accept="image/*" capture="environment" class="hidden" style="display:none;">
                                                    <label for="temperature-photo-{{ $index }}" class="inline-flex h-10 cursor-pointer items-center rounded-xl bg-sky-600 px-4 text-xs font-bold text-white">{{ ($product['temperature_photo_path'] ?? null) ? 'Ganti foto' : 'Ambil/Pilih foto' }}</label>
                                                @endif
                                            </div>
                                        </div>
                                        @if(($temperaturePhotos[$index] ?? null) instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                            <p class="mt-2 truncate text-xs font-semibold text-sky-700">Foto dipilih: {{ $temperaturePhotos[$index]->getClientOriginalName() }}</p>
                                        @endif
                                        @error("temperaturePhotos.$index") <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-5">
                        <div>
                            <h3 class="font-bold">3. Hasil Akhir & Dokumentasi Batch</h3>
                            <p class="mt-1 text-xs text-slate-500">Satu batch cukup memiliki satu hasil akhir utama dan satu foto dokumentasi wajib.</p>
                        </div>

                        @php($output = $finishedOutputDocumentations[0] ?? null)
                        @if($output)
                            <div class="mt-4 rounded-2xl border border-emerald-200 bg-white p-4">
                                <div class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_180px]">
                                    <label>
                                        <span class="mb-1 block text-xs font-semibold text-slate-600">Jumlah hasil <span class="text-rose-500">*</span></span>
                                        <input wire:model="finishedOutputDocumentations.0.output_quantity" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) type="number" min="0" step=".001" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Contoh: 1080">
                                        @error('finishedOutputDocumentations.0.output_quantity') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                    </label>
                                    <label>
                                        <span class="mb-1 block text-xs font-semibold text-slate-600">Satuan <span class="text-rose-500">*</span></span>
                                        <input wire:model="finishedOutputDocumentations.0.output_unit" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="kg / pcs / porsi / pack">
                                        @error('finishedOutputDocumentations.0.output_unit') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                    </label>
                                </div>

                                <input type="hidden" wire:model="finishedOutputDocumentations.0.caption">
                                <input type="hidden" wire:model="finishedOutputDocumentations.0.captured_at">

                                <div class="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-3">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <p class="text-xs font-semibold text-slate-700">Dokumentasi Monitoring Produksi <span class="text-rose-500">*</span></p>
                                            <p class="mt-0.5 text-[11px] text-slate-500">Wajib satu foto untuk batch ini.</p>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if($output['photo_path'] ?? null)
                                                <x-v3.documentation-button :url="Storage::disk('public')->url($output['photo_path'])" :title="'Monitoring Produksi · '.($selected->product_name ?: $selected->menu_name_snapshot)" label="Lihat foto" />
                                            @endif
                                            @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                                                <input id="finished-output-photo" wire:model="finishedOutputPhotos.0" type="file" accept="image/*" capture="environment" class="hidden" style="display:none;">
                                                <label for="finished-output-photo" class="inline-flex h-10 cursor-pointer items-center rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white">{{ ($output['photo_path'] ?? null) ? 'Ganti foto' : 'Ambil/Pilih foto' }}</label>
                                            @endif
                                        </div>
                                    </div>
                                    @if(($finishedOutputPhotos[0] ?? null) instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile)
                                        <p class="mt-2 truncate text-xs font-semibold text-emerald-700">Foto dipilih: {{ $finishedOutputPhotos[0]->getClientOriginalName() }}</p>
                                    @endif
                                    @error('finishedOutputPhotos.0') <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endif
                    </div>

                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <label class="block">
                            <span class="mb-1 block text-xs font-semibold text-slate-600">Catatan Pengolahan</span>
                            <textarea wire:model="notes" @disabled(!$canEdit || $selected->state !== \App\Enums\ProcessingBatchState::InProgress) rows="3" class="w-full rounded-xl border p-3 text-sm" placeholder="Opsional"></textarea>
                        </label>

                        @if($canEdit && $selected->state === \App\Enums\ProcessingBatchState::InProgress)
                            <div class="mt-5 flex flex-col gap-3 border-t border-slate-200 pt-4">
                                <div class="rounded-xl bg-slate-50 p-3 text-xs text-slate-600">
                                    Sebelum selesai: minimal 1 bahan baku manual, 1 suhu pangan matang + foto, 1 hasil akhir, dan 1 foto monitoring produksi.
                                </div>
                                <div class="flex flex-wrap justify-end gap-2">
                                    <button type="button" wire:click="save" class="h-11 rounded-xl border border-slate-300 bg-white px-5 text-xs font-bold text-slate-700">Simpan sementara</button>
                                    <button type="button" wire:click="complete" wire:confirm="Selesaikan dan serahkan batch ini ke Pemorsian? Data akan dikunci." class="h-11 rounded-xl bg-emerald-600 px-5 text-xs font-bold text-white">Selesaikan & Serahkan ke Pemorsian</button>
                                </div>
                            </div>
                        @endif
                    </div>

                    @if($canSubmit && $selected->state === \App\Enums\ProcessingBatchState::Completed && in_array($selected->status, [\App\Enums\OperationalReportStatus::Draft, \App\Enums\OperationalReportStatus::RevisionRequired], true))
                        <div class="flex justify-end">
                            <button wire:click="submit" class="rounded-xl bg-sky-700 px-5 py-3 text-xs font-bold text-white">Ajukan laporan Pengolahan</button>
                        </div>
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
