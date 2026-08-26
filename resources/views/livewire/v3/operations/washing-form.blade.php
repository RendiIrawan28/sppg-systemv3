@php
    $state = $record?->state;
    $status = $record?->status;
    $isPlanned = $state === App\Enums\WashingSessionState::Planned;
    $isReceived = $state === App\Enums\WashingSessionState::Received;
    $isWashing = $state === App\Enums\WashingSessionState::Washing;
    $isReady = $state === App\Enums\WashingSessionState::Ready;
    $rawWaste = $data['has_food_waste'] ?? null;
    $hasWaste = in_array($rawWaste, [true, 1, '1'], true)
        ? true
        : (in_array($rawWaste, [false, 0, '0'], true) ? false : null);
    $inputClass = 'h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-sky-400 focus:ring-2 focus:ring-sky-100 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-sky-400 dark:focus:ring-sky-500/20 dark:disabled:bg-slate-800 dark:disabled:text-slate-500';
    $textareaClass = 'w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800 outline-none transition placeholder:text-slate-400 focus:border-sky-400 focus:ring-2 focus:ring-sky-100 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100 dark:placeholder:text-slate-500 dark:focus:border-sky-400 dark:focus:ring-sky-500/20 dark:disabled:bg-slate-800 dark:disabled:text-slate-500';
@endphp

<x-v3.shell :$unit :$navigation :$roleLabel title="Rincian Pencucian" eyebrow="Sesi ompreng">
    <div class="mx-auto max-w-[1200px] space-y-5 text-slate-900 dark:text-slate-100">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <a wire:navigate href="{{ route('v3.operations.index', ['module' => 'pencucian']) }}" class="text-xs font-bold text-sky-700 hover:text-sky-800 dark:text-sky-300 dark:hover:text-sky-200">← Kembali ke daftar</a>
                <p class="mt-4 text-xs font-bold uppercase tracking-[.18em] text-sky-700 dark:text-sky-300">{{ $record->session_number }}</p>
                <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">{{ $record->containerCollectionRun?->run_number ?: $record->distributionRun?->route_name ?: 'Pengambilan Ompreng' }}</h2>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    {{ $record->washing_date?->translatedFormat('d F Y') }} · Driver {{ $record->containerCollectionRun?->driver_name_snapshot ?: $record->distributionRun?->driver_name ?: '—' }}
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <span class="rounded-full bg-sky-100 px-3 py-1.5 text-xs font-bold text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">{{ $state?->label() }}</span>
                <span class="rounded-full bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-200">{{ $status?->label() }}</span>
            </div>
        </div>

        @if ($actionMessage)
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-400/30 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $actionMessage }}</div>
        @endif
        @error('action')
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700 dark:border-rose-400/30 dark:bg-rose-500/10 dark:text-rose-300">{{ $message }}</div>
        @enderror

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[.14em] text-slate-500 dark:text-slate-400">Data dari Pengambilan Ompreng</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Snapshot serah-terima ompreng</h3>
                </div>
                <span class="rounded-xl bg-slate-100 px-3 py-2 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">Tidak dapat diubah</span>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                @foreach ([
                    ['Total hasil pengambilan', $record->distribution_expected_containers],
                    ['Dilaporkan dibawa kembali', $record->distribution_returned_containers],
                ] as [$label, $value])
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-950/70">
                        <p class="text-[10px] font-bold uppercase tracking-[.12em] text-slate-500 dark:text-slate-400">{{ $label }}</p>
                        <p class="mt-2 text-xl font-bold text-slate-950 dark:text-white">{{ number_format($value, 0, ',', '.') }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <form wire:submit="save" class="space-y-5">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[.14em] text-sky-700 dark:text-sky-300">Tahap 1</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Penerimaan fisik ompreng</h3>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Hitung fisik ompreng yang benar-benar diterima dari driver.</p>
                </div>

                <div class="mt-5 grid gap-4 sm:grid-cols-3">
                    <label>
                        <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">Seharusnya diserahkan</span>
                        <input value="{{ $record->expected_containers }}" disabled class="{{ $inputClass }}">
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">Diterima fisik</span>
                        <input wire:model="data.received_containers" type="number" min="0" @disabled(!$isPlanned || !$canUpdate) class="{{ $inputClass }}">
                        @error('data.received_containers')<span class="mt-1 block text-xs text-rose-600 dark:text-rose-300">{{ $message }}</span>@enderror
                    </label>
                    <label>
                        <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">Rusak/tidak layak saat diterima</span>
                        <input wire:model="data.damaged_containers" type="number" min="0" @disabled(!$isPlanned || !$canUpdate) class="{{ $inputClass }}">
                    </label>
                </div>

                <label class="mt-4 block">
                    <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-slate-300">Catatan penerimaan atau selisih</span>
                    <textarea wire:model="data.notes" rows="3" @disabled(!$editable || !$canUpdate) class="{{ $textareaClass }}" placeholder="Wajib diisi jika jumlah atau kondisi berbeda dari jumlah yang dibawa kembali oleh driver."></textarea>
                </label>

                @if ($isPlanned && isset($actions['receive']))
                    <div class="mt-4 flex justify-end">
                        <button type="button" wire:click="workflow('receive')" wire:loading.attr="disabled" class="inline-flex h-11 items-center rounded-xl bg-sky-600 px-5 text-xs font-bold text-white transition hover:bg-sky-700 disabled:opacity-60 dark:bg-sky-500 dark:text-slate-950 dark:hover:bg-sky-400">Terima ompreng</button>
                    </div>
                @else
                    <div class="mt-4 grid gap-3 sm:grid-cols-3">
                        <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-950/70"><p class="text-[10px] font-bold uppercase text-slate-400">Diterima</p><p class="mt-1 font-bold">{{ number_format($record->received_containers, 0, ',', '.') }}</p></div>
                        <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-950/70"><p class="text-[10px] font-bold uppercase text-slate-400">Selisih</p><p class="mt-1 font-bold {{ $record->receiving_difference === 0 ? 'text-emerald-600 dark:text-emerald-300' : 'text-amber-600 dark:text-amber-300' }}">{{ $record->receiving_difference > 0 ? '+' : '' }}{{ number_format($record->receiving_difference, 0, ',', '.') }}</p></div>
                        <div class="rounded-xl bg-slate-50 p-3 dark:bg-slate-950/70"><p class="text-[10px] font-bold uppercase text-slate-400">Diterima pada</p><p class="mt-1 font-bold">{{ $record->received_at?->format('H:i') ?: '—' }}</p></div>
                    </div>
                @endif
            </section>

            @if (!$isPlanned)
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.14em] text-amber-700 dark:text-amber-300">Tahap 2</p>
                        <h3 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Pencatatan limbah makanan</h3>
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Bagian ini wajib diselesaikan sebelum proses pencucian dimulai.</p>
                    </div>

                    <div class="mt-5 grid gap-3 sm:grid-cols-2">
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition {{ $hasWaste === true ? 'border-amber-400 bg-amber-50 dark:border-amber-400/50 dark:bg-amber-500/10' : 'border-slate-200 dark:border-slate-700' }}">
                            <input wire:model.live="data.has_food_waste" type="radio" value="1" @disabled(!$isReceived || !$canUpdate) class="mt-0.5 size-4">
                            <span><strong class="block text-sm">Terdapat limbah makanan</strong><span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">Catat jenis, jumlah, penanganan, penerima, dan foto.</span></span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition {{ $hasWaste === false ? 'border-emerald-400 bg-emerald-50 dark:border-emerald-400/50 dark:bg-emerald-500/10' : 'border-slate-200 dark:border-slate-700' }}">
                            <input wire:model.live="data.has_food_waste" type="radio" value="0" @disabled(!$isReceived || !$canUpdate) class="mt-0.5 size-4">
                            <span><strong class="block text-sm">Tidak terdapat limbah makanan</strong><span class="mt-1 block text-xs text-slate-500 dark:text-slate-400">Gunakan untuk ompreng yang diterima tanpa sisa makanan.</span></span>
                        </label>
                    </div>

                    @if ($hasWaste === true)
                        <div class="mt-5 space-y-3">
                            @foreach ($relations['wasteRecords'] ?? [] as $index => $row)
                                <div wire:key="washing-waste-{{ $row['_id'] ?? 'new-'.$index }}" class="rounded-2xl border border-amber-200 bg-amber-50/60 p-4 dark:border-amber-400/25 dark:bg-amber-500/5">
                                    <div class="flex items-center justify-between gap-3">
                                        <p class="text-xs font-bold text-amber-800 dark:text-amber-200">Limbah {{ $index + 1 }}</p>
                                        @if ($isReceived && $canUpdate)
                                            <button type="button" wire:click="removeRelationRow('wasteRecords', {{ $index }})" class="text-xs font-bold text-rose-600 hover:text-rose-700 dark:text-rose-300">Hapus</button>
                                        @endif
                                    </div>
                                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                                        <label class="lg:col-span-2"><span class="mb-1 block text-xs font-semibold">Jenis limbah</span><input wire:model="relations.wasteRecords.{{ $index }}.waste_type" @disabled(!$isReceived || !$canUpdate) class="{{ $inputClass }}" placeholder="Contoh: sisa makanan campuran"></label>
                                        <label><span class="mb-1 block text-xs font-semibold">Jumlah</span><input wire:model="relations.wasteRecords.{{ $index }}.quantity" type="number" min="0" step="0.001" @disabled(!$isReceived || !$canUpdate) class="{{ $inputClass }}"></label>
                                        <label><span class="mb-1 block text-xs font-semibold">Satuan</span><select wire:model="relations.wasteRecords.{{ $index }}.unit" @disabled(!$isReceived || !$canUpdate) class="{{ $inputClass }}"><option value="">Pilih</option><option value="kg">kg</option><option value="gram">gram</option><option value="liter">liter</option><option value="wadah">wadah</option><option value="karung">karung</option></select></label>
                                        <label class="lg:col-span-2"><span class="mb-1 block text-xs font-semibold">Metode penanganan</span><select wire:model="relations.wasteRecords.{{ $index }}.disposal_method" @disabled(!$isReceived || !$canUpdate) class="{{ $inputClass }}"><option value="">Pilih metode</option><option value="diserahkan">Diserahkan ke pengelola limbah</option><option value="kompos">Diolah menjadi kompos</option><option value="pakan">Diserahkan untuk pakan</option><option value="dibuang">Dibuang sesuai prosedur</option><option value="lainnya">Metode lainnya</option></select></label>
                                        <label class="lg:col-span-2"><span class="mb-1 block text-xs font-semibold">Diserahkan kepada</span><input wire:model="relations.wasteRecords.{{ $index }}.handed_over_to" @disabled(!$isReceived || !$canUpdate) class="{{ $inputClass }}" placeholder="Nama penerima/pengelola limbah"></label>
<label class="lg:col-span-2"><span class="mb-1 block text-xs font-semibold">Foto limbah</span><input wire:model="uploads.wasteRecords.{{ $index }}.photo_path" type="file" accept="image/*" @disabled(!$isReceived || !$canUpdate) class="block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs dark:border-slate-700 dark:bg-slate-950">@if (!empty($row['photo_path']))<x-v3.documentation-button :url="\Illuminate\Support\Facades\Storage::disk('public')->url($row['photo_path'])" :title="'Foto limbah pencucian · '.($row['waste_type'] ?: 'Item '.($index + 1))" label="Lihat foto tersimpan" class="mt-2" />@endif</label>
                                        <label class="lg:col-span-2"><span class="mb-1 block text-xs font-semibold">Catatan</span><textarea wire:model="relations.wasteRecords.{{ $index }}.notes" rows="2" @disabled(!$isReceived || !$canUpdate) class="{{ $textareaClass }}"></textarea></label>
                                    </div>
                                </div>
                            @endforeach

                            @if ($isReceived && $canUpdate)
                                <button type="button" wire:click="addRelationRow('wasteRecords')" class="inline-flex h-10 items-center rounded-xl border border-amber-300 px-4 text-xs font-bold text-amber-700 hover:bg-amber-50 dark:border-amber-400/40 dark:text-amber-300 dark:hover:bg-amber-500/10">+ Tambah jenis limbah</button>
                            @endif
                        </div>

                        <div class="mt-5 rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
                            <p class="text-xs font-bold uppercase tracking-[.12em] text-slate-500 dark:text-slate-400">Berita acara serah-terima limbah bersama</p>
                            <p class="mt-2 text-sm text-slate-600 dark:text-slate-300">Simpan daftar limbah terlebih dahulu, kemudian lengkapi satu berita acara yang juga digunakan oleh Persiapan dan Kebersihan.</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                @if ($record->wasteHandoverReport)
                                    <a wire:navigate href="{{ route('v3.waste-handovers.show', $record->wasteHandoverReport) }}" class="inline-flex h-10 items-center rounded-xl bg-sky-600 px-4 text-xs font-bold text-white">Buka berita acara</a>
                                    <a target="_blank" href="{{ route('v3.waste-handovers.pdf', $record->wasteHandoverReport) }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-bold dark:border-slate-700">Unduh PDF</a>
                                @else
                                    <a wire:navigate href="{{ route('v3.waste-handovers.create', ['division' => 'washing', 'source_type' => 'washing_session', 'source_id' => $record->id, 'source_reference' => $record->session_number]) }}" class="inline-flex h-10 items-center rounded-xl bg-amber-500 px-4 text-xs font-bold text-white">Buat berita acara limbah</a>
                                @endif
                            </div>
                        </div>
                    @elseif ($hasWaste === false)
                        <label class="mt-5 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-400/25 dark:bg-emerald-500/10">
                            <input wire:model="data.no_waste_confirmed" type="checkbox" @disabled(!$isReceived || !$canUpdate) class="mt-0.5 size-4 rounded">
                            <span class="text-sm font-semibold text-emerald-800 dark:text-emerald-200">Saya memastikan tidak terdapat sisa makanan pada ompreng yang diterima.</span>
                        </label>
                    @endif

                    @if ($isReceived && isset($actions['waste']))
                        <div class="mt-5 flex justify-end">
                            <button type="button" wire:click="workflow('waste')" wire:loading.attr="disabled" class="inline-flex h-11 items-center rounded-xl bg-amber-500 px-5 text-xs font-bold text-white transition hover:bg-amber-600 disabled:opacity-60 dark:text-slate-950">{{ $record->wasteHandlingCompleted() ? 'Perbarui data limbah' : 'Simpan pencatatan limbah' }}</button>
                        </div>
                    @elseif ($record->wasteHandlingCompleted())
                        <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 dark:border-emerald-400/25 dark:bg-emerald-500/10">
                            <p class="text-sm font-bold text-emerald-700 dark:text-emerald-300">Pencatatan limbah selesai.</p>
                            @if ($record->has_food_waste && $record->wasteHandoverReport)
                                <a target="_blank" href="{{ route('v3.waste-handovers.pdf', $record->wasteHandoverReport) }}" class="text-xs font-bold text-emerald-700 underline dark:text-emerald-300">Unduh berita acara limbah</a>
                            @endif
                        </div>
                    @endif
                </section>
            @endif

            @if ($isWashing || $isReady || $state === App\Enums\WashingSessionState::Completed)
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.14em] text-emerald-700 dark:text-emerald-300">Tahap 3</p>
                        <h3 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Proses dan hasil pencucian</h3>
                    </div>

                    <div class="mt-5 space-y-3">
                        @foreach ($relations['checklistItems'] ?? [] as $index => $row)
                            <label wire:key="washing-check-{{ $row['_id'] }}" class="flex items-start gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700 dark:bg-slate-950/50">
                                <input wire:model="relations.checklistItems.{{ $index }}.is_passed" type="checkbox" @disabled(!$isWashing || !$canUpdate) class="mt-0.5 size-4 rounded">
                                <span class="min-w-0 flex-1"><strong class="block text-sm text-slate-800 dark:text-slate-100">{{ $row['item_name'] }}</strong><input wire:model="relations.checklistItems.{{ $index }}.notes" @disabled(!$isWashing || !$canUpdate) class="mt-2 h-9 w-full rounded-lg border border-slate-200 bg-white px-3 text-xs dark:border-slate-700 dark:bg-slate-900" placeholder="Catatan opsional"></span>
                            </label>
                        @endforeach
                    </div>

                    <div class="mt-5 grid gap-4 sm:grid-cols-2">
                        <label><span class="mb-1 block text-xs font-semibold">Ompreng bersih dan siap digunakan</span><input wire:model="data.clean_containers" type="number" min="0" @disabled(!$isWashing || !$canUpdate) class="{{ $inputClass }}"></label>
                        <label><span class="mb-1 block text-xs font-semibold">Ompreng rusak/tidak layak</span><input wire:model="data.damaged_containers" type="number" min="0" @disabled(!$isWashing || !$canUpdate) class="{{ $inputClass }}"></label>
                    </div>

                    <div class="mt-5 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-xs font-bold uppercase tracking-[.12em] text-slate-500 dark:text-slate-400">Foto hasil pencucian</p>
                                <p class="mt-1 text-xs text-slate-400">Minimal satu foto wajib diunggah sebelum pencucian diselesaikan.</p>
                            </div>
                            @if ($isWashing && $canUpdate)
                                <button type="button" wire:click="addWashingResultPhoto" wire:loading.attr="disabled" wire:target="addWashingResultPhoto" class="inline-flex h-10 items-center rounded-xl bg-sky-600 px-4 text-xs font-bold text-white transition hover:bg-sky-700 disabled:opacity-60 dark:bg-sky-500 dark:text-slate-950 dark:hover:bg-sky-400">
                                    + Tambah foto hasil
                                </button>
                            @endif
                        </div>
                        @if (($relations['documentations'] ?? []) === [])
                            <div class="rounded-xl border border-dashed border-slate-300 bg-slate-50 px-4 py-6 text-center dark:border-slate-700 dark:bg-slate-950/60">
                                <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">Belum ada foto hasil pencucian.</p>
                                @if ($isWashing && $canUpdate)
                                    <button type="button" wire:click="addWashingResultPhoto" wire:loading.attr="disabled" wire:target="addWashingResultPhoto" class="mt-3 inline-flex h-10 items-center rounded-xl bg-sky-600 px-4 text-xs font-bold text-white transition hover:bg-sky-700 disabled:opacity-60 dark:bg-sky-500 dark:text-slate-950 dark:hover:bg-sky-400">
                                        + Tambah foto sekarang
                                    </button>
                                @endif
                            </div>
                        @endif
                        @foreach ($relations['documentations'] ?? [] as $index => $row)
                            <div wire:key="washing-photo-{{ $row['_id'] ?? 'new-'.$index }}" class="grid gap-3 rounded-xl border border-slate-200 p-4 dark:border-slate-700 sm:grid-cols-[1fr_auto]">
                                <div>
                                    <input wire:model="uploads.documentations.{{ $index }}.photo_path" type="file" accept="image/*" @disabled(!$isWashing || !$canUpdate) class="block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs dark:border-slate-700 dark:bg-slate-950">
                                    <input wire:model="relations.documentations.{{ $index }}.caption" @disabled(!$isWashing || !$canUpdate) class="mt-2 h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs dark:border-slate-700 dark:bg-slate-950" placeholder="Keterangan foto">
@if (!empty($row['photo_path']))<x-v3.documentation-button :url="\Illuminate\Support\Facades\Storage::disk('public')->url($row['photo_path'])" :title="'Dokumentasi hasil pencucian · '.($row['caption'] ?: 'Foto '.($index + 1))" label="Lihat foto tersimpan" class="mt-2" />@endif
                                </div>
                                @if ($isWashing && $canUpdate)<button type="button" wire:click="removeRelationRow('documentations', {{ $index }})" class="self-start text-xs font-bold text-rose-600 dark:text-rose-300">Hapus</button>@endif
                            </div>
                        @endforeach
                    </div>

                    @if ($isWashing && isset($actions['complete']))
                        <div class="mt-5 flex justify-end">
                            <button type="button" wire:click="workflow('complete')" wire:confirm="Selesaikan pencucian dan tandai ompreng siap digunakan?" class="inline-flex h-11 items-center rounded-xl bg-emerald-600 px-5 text-xs font-bold text-white transition hover:bg-emerald-700 dark:bg-emerald-500 dark:text-slate-950 dark:hover:bg-emerald-400">Selesaikan pencucian</button>
                        </div>
                    @endif
                </section>
            @endif

            @if ($isReady || in_array($status, [App\Enums\OperationalReportStatus::Submitted, App\Enums\OperationalReportStatus::DivisionApproved, App\Enums\OperationalReportStatus::Verified], true))
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-start">
                        <div>
                            <p class="text-[10px] font-bold uppercase tracking-[.14em] text-violet-700 dark:text-violet-300">Laporan harian</p>
                            <h3 class="mt-1 text-lg font-bold text-slate-950 dark:text-white">Pengajuan seluruh sesi tanggal {{ $record->washing_date?->translatedFormat('d F Y') }}</h3>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Satu tindakan akan memproses seluruh sesi Pencucian pada tanggal yang sama.</p>
                        </div>
                        @if ($status === App\Enums\OperationalReportStatus::Verified)
                            <a href="{{ route('washing-sessions.pdf', $record) }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-700 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800">Unduh laporan harian</a>
                        @endif
                    </div>

                    @if ($washingDailyIssues !== [] && in_array($status, [App\Enums\OperationalReportStatus::Draft, App\Enums\OperationalReportStatus::RevisionRequired], true))
                        <div class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-400/25 dark:bg-amber-500/10">
                            <p class="text-xs font-bold text-amber-800 dark:text-amber-200">Laporan belum dapat diajukan:</p>
                            <ul class="mt-2 space-y-1 text-xs text-amber-700 dark:text-amber-300">@foreach ($washingDailyIssues as $issue)<li>• {{ $issue }}</li>@endforeach</ul>
                        </div>
                    @endif

                    <label class="mt-4 block"><span class="mb-1 block text-xs font-semibold">Catatan pengajuan/persetujuan</span><textarea wire:model="workflowNotes" rows="3" class="{{ $textareaClass }}" placeholder="Isi catatan revisi jika laporan perlu dikembalikan."></textarea></label>

                    <div class="mt-4 flex flex-wrap justify-end gap-2">
                        @if (isset($actions['submit']))
                            <button type="button" wire:click="workflow('submit')" wire:confirm="Ajukan seluruh laporan Pencucian tanggal ini?" class="h-11 rounded-xl bg-violet-600 px-5 text-xs font-bold text-white hover:bg-violet-700 disabled:opacity-60" @disabled($washingDailyIssues !== [])>Ajukan seluruh sesi</button>
                        @endif
                        @if (isset($actions['verify']))
                            <button type="button" wire:click="workflow('verify')" wire:confirm="Setujui seluruh laporan Pencucian tanggal ini?" class="h-11 rounded-xl bg-emerald-600 px-5 text-xs font-bold text-white hover:bg-emerald-700">Setujui seluruh sesi</button>
                        @endif
                        @if (isset($actions['revision']))
                            <button type="button" wire:click="workflow('revision')" wire:confirm="Kembalikan seluruh laporan Pencucian untuk revisi?" class="h-11 rounded-xl bg-rose-600 px-5 text-xs font-bold text-white hover:bg-rose-700">Minta revisi</button>
                        @endif
                    </div>
                </section>
            @endif

            @if ($isReceived && isset($actions['start']))
                <section class="rounded-2xl border border-sky-200 bg-sky-50 p-5 dark:border-sky-400/25 dark:bg-sky-500/10">
                    <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
                        <div><p class="font-bold text-sky-900 dark:text-sky-100">Ompreng dan limbah sudah tercatat.</p><p class="mt-1 text-xs text-sky-700 dark:text-sky-300">Mulai pencucian untuk membuka checklist dan dokumentasi hasil.</p></div>
                        <button type="button" wire:click="workflow('start')" wire:confirm="Mulai proses pencucian?" class="h-11 rounded-xl bg-sky-600 px-5 text-xs font-bold text-white hover:bg-sky-700 dark:bg-sky-500 dark:text-slate-950 dark:hover:bg-sky-400">Mulai pencucian</button>
                    </div>
                </section>
            @endif
        </form>
    </div>
</x-v3.shell>
