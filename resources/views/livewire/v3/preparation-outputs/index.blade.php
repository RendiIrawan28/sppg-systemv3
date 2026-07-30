<x-v3.shell :$unit :$navigation :$roleLabel title="Penyimpanan Hasil Persiapan" eyebrow="Bahan siap pakai">
    <div class="mx-auto max-w-[1500px] space-y-5 text-slate-900 dark:text-slate-100">
        @if(session('v3.status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700 dark:border-emerald-400/30 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('v3.status') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700 dark:border-rose-400/30 dark:bg-rose-500/10 dark:text-rose-300">{{ $errors->first() }}</div>
        @endif

        <section class="rounded-[28px] bg-[#081d3a] p-6 text-white">
            <p class="text-xs font-bold uppercase tracking-[.18em] text-cyan-200">Penyimpanan sementara</p>
            <h2 class="mt-2 text-2xl font-bold">Hasil Persiapan untuk Pengolahan dan Pemorsian</h2>
            <p class="mt-2 max-w-4xl text-sm text-slate-300">Simpan bumbu, bahan potong, buah yang sudah dipilah, atau hasil lain. Satuan hasil tidak harus sama dengan satuan bahan saat diambil dari Gudang.</p>
        </section>

        @if($canCreate)
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div><p class="text-xs font-bold uppercase tracking-[.14em] text-sky-700 dark:text-sky-300">Tambah hasil</p><h3 class="mt-1 text-lg font-bold">Catat bahan yang sudah siap disimpan</h3></div>
                    <a wire:navigate href="{{ route('v3.preparation.index') }}" class="text-xs font-bold text-sky-700 dark:text-sky-300">Kembali ke sesi Persiapan</a>
                </div>
                <div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <label><span class="mb-1 block text-xs font-semibold">Sesi Persiapan</span><select wire:model.live="sessionId" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950"><option value="">Pilih sesi</option>@foreach($sessions as $session)<option value="{{ $session->id }}">{{ $session->session_number }} · {{ $session->preparation_date?->format('d/m/Y') }}</option>@endforeach</select></label>
                    <label><span class="mb-1 block text-xs font-semibold">Bahan asal</span><select wire:model.live="itemId" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950"><option value="">Pilih bahan</option>@foreach($selectedItems as $item)<option value="{{ $item->id }}">{{ $item->ingredient_name_snapshot }}</option>@endforeach</select></label>
                    <label><span class="mb-1 block text-xs font-semibold">Nama hasil</span><input wire:model="outputName" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="Contoh: Bumbu halus"></label>
                    <label><span class="mb-1 block text-xs font-semibold">Tujuan penggunaan</span><select wire:model="targetDivision" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950"><option value="processing">Pengolahan</option><option value="portioning">Pemorsian</option><option value="both">Pengolahan atau Pemorsian</option></select></label>
                    <label><span class="mb-1 block text-xs font-semibold">Jumlah hasil</span><input wire:model="quantity" type="number" min="0" step="0.001" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950"></label>
                    <label><span class="mb-1 block text-xs font-semibold">Satuan hasil</span><input wire:model="unitSnapshot" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="kg, pcs, wadah, tray"></label>
                    <label><span class="mb-1 block text-xs font-semibold">Lokasi penyimpanan</span><input wire:model="storageLocation" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="Chiller, rak bumbu, area buah"></label>
                    <label><span class="mb-1 block text-xs font-semibold">Batas penggunaan</span><input wire:model="expiresAt" type="datetime-local" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950"></label>
                    <label class="md:col-span-2"><span class="mb-1 block text-xs font-semibold">Foto hasil</span><input wire:model="outputPhoto" type="file" accept="image/*" class="block w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs dark:border-slate-700 dark:bg-slate-950"></label>
                    <label class="md:col-span-2"><span class="mb-1 block text-xs font-semibold">Catatan</span><input wire:model="outputNotes" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950" placeholder="Kondisi, wadah, atau informasi penggunaan"></label>
                </div>
                <div class="mt-4 flex justify-end"><button type="button" wire:click="createOutput" wire:loading.attr="disabled" class="h-11 rounded-xl bg-sky-600 px-5 text-xs font-bold text-white hover:bg-sky-700 disabled:opacity-60">Simpan hasil Persiapan</button></div>
            </section>
        @endif

        <section class="space-y-4">
            @forelse($outputs as $output)
                @php
                    $targetLabel = match($output->target_division) { 'processing' => 'Pengolahan', 'portioning' => 'Pemorsian', default => 'Pengolahan / Pemorsian' };
                    $canRequestProcessing = $canTakeProcessing && $output->isAvailableFor('processing');
                    $canRequestPortioning = $canTakePortioning && $output->isAvailableFor('portioning');
                @endphp
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-700 dark:bg-slate-900 dark:shadow-none">
                    <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2"><h3 class="text-lg font-bold">{{ $output->output_name }}</h3><span class="rounded-full bg-sky-100 px-2.5 py-1 text-[10px] font-bold text-sky-700 dark:bg-sky-500/15 dark:text-sky-300">{{ $targetLabel }}</span><span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-600 dark:bg-slate-700 dark:text-slate-200">{{ str($output->state)->replace('_', ' ')->title() }}</span></div>
                            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Asal: {{ $output->source_ingredient_name_snapshot ?: '—' }} · {{ $output->session?->session_number }} · Lokasi: {{ $output->storage_location ?: 'belum dicatat' }}</p>
                            @if($output->notes)<p class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ $output->notes }}</p>@endif
                        </div>
                        <div class="rounded-xl bg-emerald-50 px-5 py-3 text-right dark:bg-emerald-500/10"><p class="text-[10px] font-bold uppercase tracking-[.12em] text-emerald-700 dark:text-emerald-300">Tersedia</p><p class="mt-1 text-xl font-bold text-emerald-900 dark:text-emerald-100">{{ number_format((float)$output->available_quantity, 3, ',', '.') }} {{ $output->unit_snapshot }}</p><p class="text-[10px] text-emerald-700 dark:text-emerald-300">Awal {{ number_format((float)$output->quantity, 3, ',', '.') }} {{ $output->unit_snapshot }}</p></div>
                    </div>

                    @if($output->photo_path)
                        <x-v3.documentation-button :url="Storage::disk('public')->url($output->photo_path)" :title="$output->output_name" label="Lihat foto hasil" class="mt-3" />
                    @endif

                    @if(
                        $canChangeTarget
                        && (float) $output->available_quantity > 0
                        && in_array($output->state, [App\Models\PreparationOutput::AVAILABLE, App\Models\PreparationOutput::PARTIALLY_TAKEN], true)
                    )
                        <div class="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-400/25 dark:bg-amber-500/10">
                            <p class="text-xs font-bold text-amber-900 dark:text-amber-100">Ubah tujuan penggunaan</p>
                            <p class="mt-1 text-xs text-amber-700 dark:text-amber-300">Perubahan berlaku untuk sisa barang yang masih tersedia.</p>
                            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                                <select wire:model="targetDivisions.{{ $output->id }}" class="h-10 min-w-0 flex-1 rounded-xl border border-amber-200 bg-white px-3 text-sm dark:border-amber-500/30 dark:bg-slate-950">
                                    <option value="processing" @selected($output->target_division === 'processing')>Pengolahan</option>
                                    <option value="portioning" @selected($output->target_division === 'portioning')>Pemorsian</option>
                                    <option value="both" @selected($output->target_division === 'both')>Pengolahan atau Pemorsian</option>
                                </select>
                                <button type="button" wire:click="changeTargetDivision({{ $output->id }})" wire:loading.attr="disabled" wire:target="changeTargetDivision({{ $output->id }})" class="h-10 rounded-xl bg-amber-600 px-4 text-xs font-bold text-white hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-60">Simpan tujuan</button>
                            </div>
                            @error("targetDivisions.{$output->id}") <p class="mt-2 text-xs font-semibold text-rose-600">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    @if($canRequestProcessing || $canRequestPortioning)
                        <div class="mt-5 rounded-xl border border-sky-200 bg-sky-50 p-4 dark:border-sky-400/25 dark:bg-sky-500/10">
                            <p class="text-xs font-bold text-sky-900 dark:text-sky-100">Catat pengambilan oleh divisi</p>
                            <div class="mt-3 grid gap-3 md:grid-cols-2 xl:grid-cols-5">
                                @if($canRequestProcessing && $canRequestPortioning)
                                    <select wire:model.live="requestDivisions.{{ $output->id }}" class="h-10 rounded-xl border border-sky-200 bg-white px-3 text-sm dark:border-sky-500/30 dark:bg-slate-950"><option value="">Pilih divisi</option><option value="processing">Pengolahan</option><option value="portioning">Pemorsian</option></select>
                                @elseif($canRequestProcessing)<input value="Pengolahan" disabled class="h-10 rounded-xl border border-sky-200 bg-white px-3 text-sm dark:border-sky-500/30 dark:bg-slate-950">
                                @else<input value="Pemorsian" disabled class="h-10 rounded-xl border border-sky-200 bg-white px-3 text-sm dark:border-sky-500/30 dark:bg-slate-950">@endif
                                <input wire:model="requestQuantities.{{ $output->id }}" type="number" min="0" step="0.001" class="h-10 rounded-xl border border-sky-200 bg-white px-3 text-sm dark:border-sky-500/30 dark:bg-slate-950" placeholder="Jumlah ({{ $output->unit_snapshot }})">
                                @if($canRequestProcessing)
                                    <select wire:model="processingBatchIds.{{ $output->id }}" class="h-10 rounded-xl border border-sky-200 bg-white px-3 text-sm dark:border-sky-500/30 dark:bg-slate-950"><option value="">Batch Pengolahan</option>@foreach($processingBatches as $batch)<option value="{{ $batch->id }}">{{ $batch->batch_number }} · {{ $batch->menu_name_snapshot }}</option>@endforeach</select>
                                @endif
                                @if($canRequestPortioning)
                                    <select wire:model="portioningSessionIds.{{ $output->id }}" class="h-10 rounded-xl border border-sky-200 bg-white px-3 text-sm dark:border-sky-500/30 dark:bg-slate-950"><option value="">Sesi Pemorsian</option>@foreach($portioningSessions as $session)<option value="{{ $session->id }}">{{ $session->session_number }} · {{ $session->menu_name_snapshot }}</option>@endforeach</select>
                                @endif
                                <input wire:model="requestNotes.{{ $output->id }}" class="h-10 rounded-xl border border-sky-200 bg-white px-3 text-sm dark:border-sky-500/30 dark:bg-slate-950" placeholder="Catatan pengambilan">
                            </div>
                            <div class="mt-3 flex justify-end"><button type="button" wire:click="requestWithdrawal({{ $output->id }})" class="h-10 rounded-xl bg-sky-600 px-4 text-xs font-bold text-white hover:bg-sky-700">Ambil hasil Persiapan</button></div>
                        </div>
                    @endif

                    @if($output->withdrawals->isNotEmpty())
                        <div class="mt-5 space-y-2"><p class="text-xs font-bold uppercase tracking-[.12em] text-slate-500">Riwayat pengambilan</p>
                            @foreach($output->withdrawals as $withdrawal)
                                <div class="rounded-xl border border-slate-200 p-4 dark:border-slate-700">
                                    <div class="flex flex-wrap justify-between gap-3"><div><p class="text-sm font-bold">{{ $withdrawal->destination_division === 'processing' ? 'Pengolahan' : 'Pemorsian' }} · {{ $withdrawal->taker?->name }}</p><p class="mt-1 text-xs text-slate-500">{{ number_format((float)$withdrawal->requested_quantity, 3, ',', '.') }} {{ $withdrawal->unit_snapshot }} · {{ $withdrawal->taken_at?->format('d/m/Y H:i') }}</p></div><span class="text-xs font-bold {{ $withdrawal->status === 'verified' ? 'text-emerald-600' : 'text-rose-600' }}">{{ $withdrawal->status === 'verified' ? 'Terambil' : 'Ditolak (riwayat lama)' }}</span></div>
                                    @if($withdrawal->verified_quantity !== null)
                                        <p class="mt-2 text-xs text-slate-500">Jumlah terambil: {{ number_format((float)$withdrawal->verified_quantity, 3, ',', '.') }} {{ $withdrawal->unit_snapshot }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </article>
            @empty
                <div class="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900">Belum ada hasil Persiapan yang disimpan.</div>
            @endforelse
        </section>
    </div>
</x-v3.shell>
