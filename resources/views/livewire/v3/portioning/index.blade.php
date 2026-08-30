<x-v3.shell :$unit :$navigation :$roleLabel title="Divisi Pemorsian" eyebrow="Pencatatan manual per rute">
    <div class="mx-auto max-w-[1500px] space-y-5">
        <x-v3.flash-alert />
        <x-v3.date-filter label="Tanggal Pemorsian" />
        @if($attentionRecords->isNotEmpty())
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-4"><h3 class="font-bold text-amber-900">Sesi tanggal lain yang belum selesai</h3><div class="mt-3 flex flex-wrap gap-2">@foreach($attentionRecords as $record)<button wire:click="select({{ $record->id }})" class="rounded-xl border border-amber-200 bg-white px-3 py-2 text-left text-xs"><b>{{ $record->session_number }}</b><span class="ml-2 text-slate-500">{{ $record->portioning_date?->format('d/m/Y') }}</span></button>@endforeach</div></section>
        @endif

        <section class="rounded-[28px] bg-[#081d3a] p-6 text-white">
            <p class="text-xs font-bold uppercase tracking-widest text-cyan-200">Kontrol Pemorsian</p>
            <h2 class="mt-2 text-2xl font-bold">Tambahkan hasil Pemorsian satu rute setiap kali.</h2>
            <p class="mt-2 max-w-3xl text-sm text-slate-300">
                Nama rute diisi manual. Sistem menjumlahkan seluruh porsi kecil dan besar yang telah disimpan, kemudian membandingkannya dengan target distribusi harian.
            </p>
        </section>

        @if($canCreate)
            <section class="rounded-2xl border border-sky-200 bg-sky-50 p-5">
                <div class="flex flex-col justify-between gap-3 lg:flex-row lg:items-start">
                    <div>
                        <p class="text-xs font-bold uppercase tracking-[.14em] text-sky-700">Mulai Pemorsian</p>
                        <h3 class="mt-1 text-lg font-bold text-slate-900">Pilih rencana distribusi aktif</h3>
                        <p class="mt-1 max-w-3xl text-sm text-slate-600">Sesi harus dimulai terlebih dahulu. Setelah berjalan, barang dapat diambil dari Gudang atau hasil Persiapan.</p>
                    </div>
                    <div class="rounded-xl bg-white px-4 py-3 text-xs text-slate-600 ring-1 ring-sky-100">
                        Menu, tanggal, target porsi, dan rute akan diambil otomatis.
                    </div>
                </div>

                <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
                    <label>
                        <span class="mb-1 block text-xs font-semibold text-slate-700">Rencana produksi</span>
                        <select wire:model.live="productionPlanId" class="h-11 w-full rounded-xl border border-sky-200 bg-white px-3 text-sm">
                            <option value="">Pilih rencana produksi aktif</option>
                            @forelse($productionPlans as $plan)
                                <option value="{{ $plan->id }}">
                                    {{ $plan->plan_number }} · {{ $plan->menu_name_snapshot }} · {{ $plan->distribution_date?->format('d-m-Y') }}
                                    {{ $plan->portioningSession ? '· sesi sudah tersedia' : '' }}
                                </option>
                            @empty
                                <option value="" disabled>Belum ada rencana produksi aktif</option>
                            @endforelse
                        </select>
                        @error('productionPlanId') <p class="mt-1 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                    </label>
                    <button type="button" wire:click="createFromProductionPlan" wire:loading.attr="disabled" wire:target="createFromProductionPlan" @disabled(!$selectedProductionPlan) class="h-11 rounded-xl bg-sky-700 px-5 text-xs font-bold text-white hover:bg-sky-800 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ $selectedProductionPlan?->portioningSession ? 'Buka sesi berjalan' : 'Mulai Pemorsian' }}
                    </button>
                </div>

                @if($selectedProductionPlan)
                    <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <div class="rounded-xl border border-sky-100 bg-white p-3">
                            <p class="text-[10px] font-bold uppercase text-slate-400">Menu</p>
                            <p class="mt-1 text-sm font-bold text-slate-800">{{ $selectedProductionPlan->menu_name_snapshot }}</p>
                        </div>
                        <div class="rounded-xl border border-sky-100 bg-white p-3">
                            <p class="text-[10px] font-bold uppercase text-slate-400">Tanggal Pemorsian</p>
                            <p class="mt-1 text-sm font-bold text-slate-800">{{ $selectedProductionPlan->distribution_date?->translatedFormat('d F Y') }}</p>
                        </div>
                        <div class="rounded-xl border border-sky-100 bg-white p-3">
                            <p class="text-[10px] font-bold uppercase text-slate-400">Target porsi</p>
                            <p class="mt-1 text-sm font-bold text-slate-800">{{ number_format($selectedProductionPlan->planned_small_portions, 0, ',', '.') }} kecil · {{ number_format($selectedProductionPlan->planned_large_portions, 0, ',', '.') }} besar</p>
                        </div>
                        <div class="rounded-xl border border-sky-100 bg-white p-3">
                            <p class="text-[10px] font-bold uppercase text-slate-400">Rute/Tujuan</p>
                            <p class="mt-1 text-sm font-bold text-slate-800">{{ number_format($selectedProductionPlan->destinations->where('total_portions', '>', 0)->count(), 0, ',', '.') }} tujuan</p>
                        </div>
                    </div>
                @endif
            </section>
        @endif

        <div class="grid gap-5 xl:grid-cols-[350px_minmax(0,1fr)]">
            <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-4">
                <h3 class="font-bold">Sesi Pemorsian</h3>
                <p class="mt-1 text-xs text-slate-500">Pilih sesi sesuai tanggal pelayanan.</p>
                <div class="mt-4 space-y-2">
                    @forelse($records as $record)
                        <button type="button" wire:click="select({{ $record->id }})" class="w-full rounded-xl border p-3 text-left transition {{ $selectedId === $record->id ? 'border-sky-400 bg-sky-50' : 'border-slate-200 hover:border-sky-200' }}">
                            <div class="flex items-start justify-between gap-2">
                                <span class="text-xs font-bold text-slate-800">{{ $record->session_number }}</span>
                                <span class="rounded-full bg-slate-100 px-2 py-1 text-[9px] font-bold text-slate-600">{{ $record->state->label() }}</span>
                            </div>
                            <p class="mt-2 text-sm font-semibold text-slate-700">{{ $record->menu_name_snapshot }}</p>
                            <p class="mt-1 text-[11px] text-slate-500">{{ $record->portioning_date?->translatedFormat('d M Y') }} · {{ number_format($record->target_total, 0, ',', '.') }} porsi</p>
                            <p class="mt-1 text-[10px] font-semibold text-sky-700">{{ $record->status->label() }}</p>
                        </button>
                    @empty
                        <div class="rounded-xl border border-dashed border-slate-200 p-6 text-center text-xs text-slate-500">Belum ada rencana Pemorsian.</div>
                    @endforelse
                </div>
            </aside>

            @if(!$selected)
                <section class="grid min-h-80 place-items-center rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center">
                    <div>
                        <h3 class="font-bold text-slate-800">Pilih sesi Pemorsian</h3>
                        <p class="mt-2 text-sm text-slate-500">Buka sesi di sebelah kiri untuk mencatat rute.</p>
                    </div>
                </section>
            @else
                @php
                    $editable = $selected->state === \App\Enums\PortioningSessionState::InProgress
                        || ($selected->state === \App\Enums\PortioningSessionState::Completed
                            && $selected->status === \App\Enums\OperationalReportStatus::RevisionRequired);
                    $smallDifference = (int) $selected->actual_small_portions - (int) $selected->target_small_portions;
                    $largeDifference = (int) $selected->actual_large_portions - (int) $selected->target_large_portions;
                    $targetMatched = $smallDifference === 0 && $largeDifference === 0;
                @endphp

                <section class="space-y-5">
                    <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                        <h3 class="font-bold text-emerald-900">Batch Pengolahan Siap</h3>
                        <p class="mt-1 text-xs text-emerald-700">Terima setiap batch secara terpisah. Target porsi tetap mengikuti rencana distribusi.</p>
                        <div class="mt-3 space-y-2">
                            @forelse($readyProcessingBatches as $batch)
                                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white p-3">
                                    <div><b>{{ $batch->batch_number }} · {{ $batch->product_name }}</b><p class="text-xs text-slate-500">{{ number_format((float)$batch->actual_output_quantity, 3, ',', '.') }} {{ $batch->actual_output_unit }} · diserahkan {{ $batch->portioning_handed_over_at?->format('H:i') }}</p></div>
                                    @if($canEdit && in_array($selected->state, [\App\Enums\PortioningSessionState::Planned, \App\Enums\PortioningSessionState::InProgress], true))<button wire:click="receiveProcessingBatch({{ $batch->id }})" class="rounded-xl bg-emerald-600 px-4 py-2 text-xs font-bold text-white">Terima Batch</button>@endif
                                </div>
                            @empty
                                <p class="rounded-xl bg-white p-3 text-xs text-slate-500">Belum ada batch baru yang diserahkan.</p>
                            @endforelse
                        </div>
                    </section>
                    @if($selected->processingBatches->isNotEmpty())
                        <section class="rounded-2xl border border-sky-200 bg-sky-50 p-5">
                            <h3 class="font-bold text-sky-900">Batch Pengolahan Diterima</h3>
                            <p class="mt-1 text-xs text-sky-700">Jumlah di bawah memakai hasil aktual dan satuan asli dari Pengolahan.</p>
                            <div class="mt-3 grid gap-3 md:grid-cols-2">
                                @foreach($selected->processingBatches as $batch)
                                    <div class="rounded-xl bg-white p-4">
                                        <b>{{ $batch->batch_number }} · {{ $batch->product_name }}</b>
                                        <p class="mt-1 text-xs text-slate-500">{{ number_format((float) $batch->actual_output_quantity, 3, ',', '.') }} {{ $batch->actual_output_unit }} · diterima {{ $batch->portioning_received_at?->format('H:i') }}</p>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    @endif
                    <section class="rounded-[26px] bg-[#081d3a] p-5 text-white">
                        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-widest text-cyan-300">{{ $selected->session_number }}</p>
                                <h2 class="mt-2 text-2xl font-bold">{{ $selected->menu_name_snapshot }}</h2>
                                <p class="mt-1 text-sm text-slate-300">{{ $selected->portioning_date?->translatedFormat('d F Y') }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-white/10 px-3 py-2 text-xs font-bold">{{ $selected->state->label() }}</span>
                                <span class="rounded-full bg-cyan-400/10 px-3 py-2 text-xs font-bold text-cyan-200">{{ $selected->status->label() }}</span>
                                @if($canExport && $selected->status === \App\Enums\OperationalReportStatus::Verified)
                                    <a href="{{ route('portioning-sessions.pdf', $selected) }}" class="rounded-xl bg-white px-4 py-2 text-xs font-bold text-slate-800">Unduh laporan</a>
                                @endif
                            </div>
                        </div>
                        @if($canEdit && in_array($selected->id, $cancellableSessionIds, true))
                            <div class="mt-4 rounded-xl border border-rose-300/30 bg-rose-300/10 p-3">
                                <label class="block text-xs font-semibold text-rose-100">Alasan pembatalan</label>
                                <textarea wire:model="cancellationReason" rows="2" class="mt-2 w-full rounded-lg border border-rose-200 bg-white p-2 text-sm text-slate-900" placeholder="Wajib diisi"></textarea>
                                @error('cancellationReason') <p class="mt-1 text-xs font-semibold text-rose-200">{{ $message }}</p> @enderror
                                <button wire:click="cancel" wire:confirm="Batalkan sesi Pemorsian ini?" class="mt-2 rounded-lg px-3 py-2 text-xs font-bold text-rose-100">Batalkan Pemorsian</button>
                            </div>
                        @endif
                        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-xl border border-white/10 bg-white/[.07] p-3">
                                <p class="text-[10px] text-slate-400">Porsi kecil</p>
                                <p class="mt-1 text-xl font-bold">{{ number_format($selected->actual_small_portions, 0, ',', '.') }} <span class="text-xs font-normal text-slate-400">/ {{ number_format($selected->target_small_portions, 0, ',', '.') }}</span></p>
                                <p class="mt-1 text-[10px] {{ $smallDifference === 0 ? 'text-emerald-300' : 'text-amber-300' }}">{{ $smallDifference === 0 ? 'Sesuai target' : ($smallDifference < 0 ? 'Kurang '.number_format(abs($smallDifference), 0, ',', '.') : 'Lebih '.number_format($smallDifference, 0, ',', '.')) }}</p>
                            </div>
                            <div class="rounded-xl border border-white/10 bg-white/[.07] p-3">
                                <p class="text-[10px] text-slate-400">Porsi besar</p>
                                <p class="mt-1 text-xl font-bold">{{ number_format($selected->actual_large_portions, 0, ',', '.') }} <span class="text-xs font-normal text-slate-400">/ {{ number_format($selected->target_large_portions, 0, ',', '.') }}</span></p>
                                <p class="mt-1 text-[10px] {{ $largeDifference === 0 ? 'text-emerald-300' : 'text-amber-300' }}">{{ $largeDifference === 0 ? 'Sesuai target' : ($largeDifference < 0 ? 'Kurang '.number_format(abs($largeDifference), 0, ',', '.') : 'Lebih '.number_format($largeDifference, 0, ',', '.')) }}</p>
                            </div>
                            <div class="rounded-xl border border-white/10 bg-white/[.07] p-3">
                                <p class="text-[10px] text-slate-400">Total tersimpan</p>
                                <p class="mt-1 text-xl font-bold">{{ number_format($selected->actual_total, 0, ',', '.') }}</p>
                                <p class="mt-1 text-[10px] text-slate-400">{{ count($routeRecords) }} rute</p>
                            </div>
                            <div class="rounded-xl border {{ $targetMatched ? 'border-emerald-300/30 bg-emerald-300/10' : 'border-amber-300/30 bg-amber-300/10' }} p-3">
                                <p class="text-[10px] {{ $targetMatched ? 'text-emerald-300' : 'text-amber-300' }}">Status target</p>
                                <p class="mt-2 text-base font-bold">{{ $targetMatched ? 'Target terpenuhi' : 'Belum sesuai target' }}</p>
                            </div>
                        </div>
                    </section>

                    @if($canEdit && $selected->state === \App\Enums\PortioningSessionState::InProgress)
                        <div class="grid gap-3 sm:grid-cols-2">
                            <a href="{{ route('v3.warehouse.withdrawals.index') }}" class="rounded-2xl border border-sky-200 bg-sky-50 p-4 text-center text-sm font-bold text-sky-700">Ambil barang dari Gudang</a>
                            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-center text-sm font-bold text-emerald-700">Hasil Persiapan yang diserahkan akan muncul otomatis</div>
                        </div>
                    @endif

                    <section class="rounded-2xl border border-slate-200 bg-white p-5">
                        <h3 class="font-bold text-slate-900">Barang dari Gudang</h3>
                        <p class="mt-1 text-xs text-slate-500">Barang langsung masuk ke sesi. Verifikasi Gudang dilakukan terpisah untuk menyesuaikan stok sistem.</p>
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            @forelse($selected->supplies->where('source_type', 'warehouse_withdrawal') as $supply)
                                <div class="rounded-xl bg-slate-50 p-4"><b>{{ $supply->supply_name }}</b><p class="mt-1 text-xs text-slate-500">{{ number_format((float) $supply->quantity, 3, ',', '.') }} {{ $supply->unit_name }} · {{ $supply->source_reference ?: 'Pengambilan Gudang' }}</p></div>
                            @empty
                                <div class="rounded-xl border border-dashed p-5 text-sm text-slate-500 md:col-span-2">Belum ada barang yang diambil dari Gudang.</div>
                            @endforelse
                        </div>
                    </section>

                    <section class="rounded-2xl border border-slate-200 bg-white p-5">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div><h3 class="font-bold text-slate-900">Hasil dari Divisi Persiapan</h3><p class="mt-1 text-xs text-slate-500">Buah atau bahan siap pakai yang telah diambil untuk sesi Pemorsian ini.</p></div>
                        </div>
                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                            @forelse($selected->preparationOutputWithdrawals->whereIn('status', [\App\Models\PreparationOutputWithdrawal::WAITING, \App\Models\PreparationOutputWithdrawal::VERIFIED]) as $withdrawal)
                                <div class="rounded-xl bg-slate-50 p-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div><b>{{ $withdrawal->output?->output_name }}</b><p class="mt-1 text-xs text-slate-500">{{ number_format((float) $withdrawal->used_quantity, 3, ',', '.') }} {{ $withdrawal->unit_snapshot }} · {{ $withdrawal->output?->storage_location ?: 'Lokasi tidak dicatat' }}</p></div>
                                        <span class="rounded-full px-2 py-1 text-[10px] font-bold {{ $withdrawal->status === \App\Models\PreparationOutputWithdrawal::VERIFIED ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">{{ $withdrawal->verification_status_label }}</span>
                                    </div>
                                    @if($withdrawal->status === \App\Models\PreparationOutputWithdrawal::WAITING && $canEdit)<button wire:click="acceptPreparationOutput({{ $withdrawal->id }})" class="mt-3 rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white">Terima</button>@endif
                                </div>
                            @empty
                                <div class="rounded-xl border border-dashed p-5 text-sm text-slate-500 md:col-span-2">Belum ada hasil Persiapan yang diambil untuk sesi ini.</div>
                            @endforelse
                        </div>
                    </section>

                    @if($selected->state === \App\Enums\PortioningSessionState::Planned)
                        <section class="rounded-2xl border border-sky-200 bg-sky-50 p-5">
                            <h3 class="font-bold text-slate-900">Siap memulai Pemorsian</h3>
                            <p class="mt-1 text-sm text-slate-600">Mulai sesi terlebih dahulu, kemudian ambil barang dari Gudang atau hasil Persiapan.</p>
                            @if($canEdit)
                                <div class="mt-4 flex justify-end"><button type="button" wire:click="start" class="h-11 rounded-xl bg-sky-700 px-5 text-xs font-bold text-white">Mulai Pemorsian</button></div>
                            @endif
                        </section>
                    @else
                        @if($editable && $canEdit)
                            <section class="rounded-2xl border border-slate-200 bg-white p-5">
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div>
                                        <h3 class="font-bold text-slate-900">{{ $routeForm['id'] ? 'Perbarui rute' : '1. Tambah hasil rute' }}</h3>
                                        <p class="mt-1 text-xs text-slate-500">Isi satu rute, simpan, kemudian lanjutkan dengan rute berikutnya.</p>
                                    </div>
                                    @if($routeForm['id'])
                                        <button type="button" wire:click="resetRouteForm" class="text-xs font-bold text-sky-700">Batal mengubah</button>
                                    @endif
                                </div>
                                <div class="mt-4 grid gap-3 lg:grid-cols-[minmax(170px,1fr)_150px_150px_minmax(200px,1.2fr)]">
                                    <label>
                                        <span class="mb-1 block text-[11px] font-semibold text-slate-600">Nama rute</span>
                                        <input wire:model="routeForm.route_name" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Contoh: Rute 1">
                                    </label>
                                    <label>
                                        <span class="mb-1 block text-[11px] font-semibold text-slate-600">Porsi kecil</span>
                                        <input wire:model="routeForm.small_portions" type="number" min="0" step="1" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold" placeholder="0">
                                    </label>
                                    <label>
                                        <span class="mb-1 block text-[11px] font-semibold text-slate-600">Porsi besar</span>
                                        <input wire:model="routeForm.large_portions" type="number" min="0" step="1" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm font-bold" placeholder="0">
                                    </label>
                                    <label>
                                        <span class="mb-1 block text-[11px] font-semibold text-slate-600">Catatan rute</span>
                                        <input wire:model="routeForm.notes" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Opsional">
                                    </label>
                                </div>
                                <div class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed border-slate-300 bg-slate-50 p-3">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @php
                                            $routePhotoName = $routePhoto instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile
                                                ? $routePhoto->getClientOriginalName()
                                                : ($routeForm['photo_original_name'] ?: ($routeForm['photo_path'] ? basename($routeForm['photo_path']) : null));
                                        @endphp
                                        @if($routeForm['photo_path'])
                                            <x-v3.documentation-button :url="Storage::disk('public')->url($routeForm['photo_path'])" :title="'Dokumentasi '.$routeForm['route_name']" label="Lihat dokumentasi" />
                                        @endif
                                        <input id="manual-route-photo" wire:model="routePhoto" type="file" accept="image/*" capture="environment" class="hidden" style="display:none">
                                        <label for="manual-route-photo" class="inline-flex h-10 cursor-pointer items-center rounded-xl bg-sky-600 px-4 text-xs font-bold text-white">{{ $routeForm['photo_path'] ? 'Ganti dokumentasi' : 'Pilih dokumentasi' }}</label>
                                        <span wire:loading wire:target="routePhoto" class="text-xs font-semibold text-sky-700">Mengunggah foto...</span>
                                        @if($routePhotoName)
                                            <span wire:loading.remove wire:target="routePhoto" class="inline-flex min-w-0 items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700">
                                                <span aria-hidden="true">✓</span>
                                                <span class="shrink-0">{{ $routePhoto instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile ? 'File dipilih:' : 'Foto tersimpan:' }}</span>
                                                <span class="max-w-56 truncate" title="{{ $routePhotoName }}">{{ $routePhotoName }}</span>
                                            </span>
                                        @endif
                                    </div>
                                    <button type="button" wire:click="saveRoute" class="h-10 rounded-xl bg-emerald-600 px-5 text-xs font-bold text-white">{{ $routeForm['id'] ? 'Simpan perubahan' : 'Simpan rute' }}</button>
                                </div>
                            </section>
                        @endif

                        <section class="rounded-2xl border border-slate-200 bg-white p-5">
                            <h3 class="font-bold text-slate-900">2. Rute yang sudah disimpan</h3>
                            <div class="mt-4 space-y-3">
                                @forelse($routeRecords as $route)
                                    <article wire:key="saved-route-{{ $route['id'] }}" class="flex flex-col justify-between gap-4 rounded-xl border border-emerald-200 bg-emerald-50/40 p-4 sm:flex-row sm:items-center">
                                        <div>
                                            <div class="flex flex-wrap items-center gap-2">
                                                <h4 class="font-bold text-slate-900">{{ $route['route_name'] }}</h4>
                                                <span class="rounded-full bg-emerald-100 px-2 py-1 text-[10px] font-bold text-emerald-700">Tersimpan {{ $route['completed_at'] }}</span>
                                            </div>
                                            <p class="mt-1 text-sm text-slate-600">Kecil {{ number_format($route['small_portions'], 0, ',', '.') }} · Besar {{ number_format($route['large_portions'], 0, ',', '.') }}</p>
                                            @if($route['notes'])<p class="mt-1 text-xs text-slate-500">{{ $route['notes'] }}</p>@endif
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-v3.documentation-button :url="Storage::disk('public')->url($route['photo_path'])" :title="'Dokumentasi '.$route['route_name']" label="Lihat foto" />
                                            @if($editable && $canEdit)
                                                <button type="button" wire:click="editRoute({{ $route['id'] }})" class="rounded-lg px-3 py-2 text-xs font-bold text-sky-700">Ubah</button>
                                                <button type="button" wire:click="deleteRoute({{ $route['id'] }})" wire:confirm="Hapus rute ini?" class="rounded-lg px-3 py-2 text-xs font-bold text-rose-600">Hapus</button>
                                            @endif
                                        </div>
                                    </article>
                                @empty
                                    <div class="rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">Belum ada rute yang disimpan.</div>
                                @endforelse
                            </div>
                        </section>

                        <section class="rounded-2xl border border-slate-200 bg-white p-5">
                            <h3 class="font-bold text-slate-900">3. Sisa makanan setelah Pemorsian</h3>
                            <p class="mt-1 text-xs text-slate-500">Dapat diisi setelah minimal satu rute disimpan.</p>
                            @if(!$routesRecorded)
                                <div class="mt-4 rounded-xl bg-amber-50 p-4 text-sm font-semibold text-amber-700">Simpan minimal satu rute terlebih dahulu.</div>
                            @else
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <button type="button" wire:click="setLeftoverMode('none')" @disabled(!$editable || !$canEdit) class="rounded-xl border px-4 py-2 text-xs font-bold {{ $leftoverMode === 'none' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600' }}">Tidak ada sisa</button>
                                    <button type="button" wire:click="setLeftoverMode('present')" @disabled(!$editable || !$canEdit) class="rounded-xl border px-4 py-2 text-xs font-bold {{ $leftoverMode === 'present' ? 'border-sky-500 bg-sky-50 text-sky-700' : 'border-slate-200 text-slate-600' }}">Ada sisa makanan</button>
                                </div>
                                @if($leftoverMode === 'present')
                                    <datalist id="portioning-leftover-units"><option value="kg"><option value="gram"><option value="liter"><option value="ml"><option value="porsi"><option value="pack"><option value="loyang"><option value="pcs"></datalist>
                                    <div class="mt-4 space-y-3">
                                        @foreach($leftovers as $index => $leftover)
                                            <div wire:key="leftover-{{ $index }}" class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                                                @php
                                                    $leftoverPhoto = $leftoverPhotos[$index] ?? null;
                                                    $leftoverPhotoName = $leftoverPhoto instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile
                                                        ? $leftoverPhoto->getClientOriginalName()
                                                        : ($leftover['photo_original_name'] ?: ($leftover['photo_path'] ? basename($leftover['photo_path']) : null));
                                                @endphp
                                                <div class="grid gap-3 lg:grid-cols-[minmax(180px,1.3fr)_130px_130px_minmax(180px,1fr)]">
                                                    <label><span class="mb-1 block text-[11px] font-semibold text-slate-600">Nama makanan</span><input wire:model="leftovers.{{ $index }}.food_type" @disabled(!$editable || !$canEdit) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></label>
                                                    <label><span class="mb-1 block text-[11px] font-semibold text-slate-600">Jumlah/berat</span><input wire:model="leftovers.{{ $index }}.quantity" @disabled(!$editable || !$canEdit) type="number" min="0.001" step=".001" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></label>
                                                    <label><span class="mb-1 block text-[11px] font-semibold text-slate-600">Satuan</span><input wire:model="leftovers.{{ $index }}.unit_name" list="portioning-leftover-units" @disabled(!$editable || !$canEdit) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></label>
                                                    <label><span class="mb-1 block text-[11px] font-semibold text-slate-600">Catatan</span><input wire:model="leftovers.{{ $index }}.notes" @disabled(!$editable || !$canEdit) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></label>
                                                </div>
                                                <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        @if($leftover['photo_path'])<x-v3.documentation-button :url="Storage::disk('public')->url($leftover['photo_path'])" :title="'Sisa '.$leftover['food_type']" label="Lihat foto sisa" />@endif
                                                        @if($editable && $canEdit)
                                                            <input id="leftover-photo-{{ $index }}" wire:model="leftoverPhotos.{{ $index }}" type="file" accept="image/*" capture="environment" class="hidden" style="display:none">
                                                            <label for="leftover-photo-{{ $index }}" class="inline-flex h-9 cursor-pointer items-center rounded-xl bg-sky-600 px-4 text-xs font-bold text-white">{{ $leftover['photo_path'] ? 'Ganti foto sisa' : 'Pilih foto sisa' }}</label>
                                                        @endif
                                                        <span wire:loading wire:target="leftoverPhotos.{{ $index }}" class="text-xs font-semibold text-sky-700">Mengunggah foto...</span>
                                                        @if($leftoverPhotoName)
                                                            <span wire:loading.remove wire:target="leftoverPhotos.{{ $index }}" class="inline-flex min-w-0 items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700">
                                                                <span aria-hidden="true">✓</span>
                                                                <span class="shrink-0">{{ $leftoverPhoto instanceof \Livewire\Features\SupportFileUploads\TemporaryUploadedFile ? 'File dipilih:' : 'Foto tersimpan:' }}</span>
                                                                <span class="max-w-56 truncate" title="{{ $leftoverPhotoName }}">{{ $leftoverPhotoName }}</span>
                                                            </span>
                                                        @endif
                                                    </div>
                                                    @if($editable && $canEdit)<button type="button" wire:click="removeLeftover({{ $index }})" class="text-xs font-bold text-rose-600">Hapus</button>@endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                    @if($editable && $canEdit)<button type="button" wire:click="addLeftover" class="mt-3 text-xs font-bold text-sky-700">+ Tambah sisa makanan</button>@endif
                                @endif

                                <label class="mt-5 block">
                                    <span class="mb-1 block text-xs font-semibold text-slate-600">Catatan Pemorsian</span>
                                    <textarea wire:model="notes" @disabled(!$editable || !$canEdit) rows="3" class="w-full rounded-xl border border-slate-200 bg-white p-3 text-sm" placeholder="{{ $targetMatched ? 'Opsional' : 'Wajib karena jumlah belum sesuai target' }}"></textarea>
                                </label>
                                @if($editable && $canEdit && $leftoverDeclared)
                                    <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4">
                                        <button type="button" wire:click="saveFinalData" class="h-11 rounded-xl border border-slate-300 bg-white px-5 text-xs font-bold text-slate-700">Simpan data akhir</button>
                                        @if($selected->state === \App\Enums\PortioningSessionState::InProgress)
                                            <button type="button" wire:click="complete" wire:confirm="Selesaikan seluruh Pemorsian?" class="h-11 rounded-xl bg-emerald-600 px-5 text-xs font-bold text-white">Selesaikan Pemorsian</button>
                                        @endif
                                    </div>
                                @endif
                            @endif
                        </section>
                    @endif

                    @if($canSubmit && $selected->state === \App\Enums\PortioningSessionState::Completed && in_array($selected->status, [\App\Enums\OperationalReportStatus::Draft, \App\Enums\OperationalReportStatus::RevisionRequired], true))
                        <div class="flex justify-end"><button type="button" wire:click="submit" class="rounded-xl bg-sky-700 px-5 py-3 text-xs font-bold text-white">Ajukan laporan Pemorsian</button></div>
                    @endif
                    @if($canApprove && in_array($selected->status, [\App\Enums\OperationalReportStatus::Submitted, \App\Enums\OperationalReportStatus::DivisionApproved], true))
                        <section class="rounded-2xl border border-sky-200 bg-sky-50 p-5">
                            <h3 class="font-bold text-slate-900">{{ $selected->status === \App\Enums\OperationalReportStatus::Submitted ? 'Pemeriksaan Kepala Divisi Pemorsian' : 'Verifikasi akhir Kepala SPPG' }}</h3>
                            <p class="mt-1 text-xs text-slate-600">{{ $selected->status === \App\Enums\OperationalReportStatus::Submitted ? 'Pastikan jumlah porsi, dokumentasi rute, dan sisa makanan telah sesuai.' : 'Setelah diverifikasi Kepala SPPG, laporan dapat diekspor oleh Asisten Lapangan.' }}</p>
                            <textarea wire:model="reviewNotes" class="mt-3 w-full rounded-xl border border-slate-200 bg-white p-3 text-sm" placeholder="Catatan pemeriksa"></textarea>
                            <div class="mt-3 flex justify-end gap-2">
                                <button type="button" wire:click="requestRevision" class="rounded-xl px-4 py-2 text-xs font-bold text-rose-700">Minta revisi</button>
                                <button type="button" wire:click="approve" class="rounded-xl bg-sky-700 px-4 py-2 text-xs font-bold text-white">{{ $selected->status === \App\Enums\OperationalReportStatus::Submitted ? 'Setujui sebagai Kepala Divisi' : 'Verifikasi sebagai Kepala SPPG' }}</button>
                            </div>
                        </section>
                    @endif
                </section>
            @endif
        </div>
    </div>
</x-v3.shell>
