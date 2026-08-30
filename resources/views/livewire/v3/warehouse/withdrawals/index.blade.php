<x-v3.shell :$unit :$navigation :$roleLabel title="Pengambilan Gudang" eyebrow="Divisi mengambil, Gudang memverifikasi setiap hari">
    <div class="mx-auto max-w-[1450px] space-y-5">
        <x-v3.flash-alert />
        <x-v3.date-filter label="Tanggal pengambilan Gudang" />
        @if($pendingOtherDates->isNotEmpty())
            <section class="rounded-2xl border border-amber-200 bg-amber-50 p-4"><h3 class="font-bold text-amber-900">Pengambilan tanggal lain menunggu verifikasi</h3><p class="mt-1 text-xs text-amber-700">{{ $pendingOtherDates->count() }} dokumen tetap perlu diperiksa Gudang.</p><div class="mt-3 flex flex-wrap gap-2">@foreach($pendingOtherDates->groupBy(fn($item) => $item->withdrawal_date?->toDateString()) as $date => $items)<button wire:click="$set('workDate', '{{ $date }}')" type="button" class="rounded-xl border border-amber-200 bg-white px-3 py-2 text-xs font-bold text-amber-800">{{ optional($items->first()->withdrawal_date)->format('d/m/Y') }} · {{ $items->count() }} dokumen</button>@endforeach</div></section>
        @endif

        <section class="rounded-[28px] bg-[#081d3a] p-6 text-white">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-cyan-200">Pengambilan langsung</p>
                    <h2 class="mt-2 text-2xl font-bold">Ambil barang dan langsung lanjutkan pekerjaan divisi.</h2>
                    <p class="mt-2 max-w-3xl text-sm text-slate-300">Barang langsung muncul di halaman divisi setelah dicatat. Gudang memeriksa kecocokan jenis dan jumlah aktual, kemudian stok resmi berkurang saat diverifikasi.</p>
                    <div class="mt-4 inline-flex rounded-xl bg-white/10 p-1"><button wire:click="$set('warehouseType','food')" class="rounded-lg px-4 py-2 text-xs font-bold {{ $warehouseType === 'food' ? 'bg-cyan-300 text-[#081d3a]' : 'text-white' }}">Pangan</button><button wire:click="$set('warehouseType','non_food')" class="rounded-lg px-4 py-2 text-xs font-bold {{ $warehouseType === 'non_food' ? 'bg-cyan-300 text-[#081d3a]' : 'text-white' }}">Non-Pangan</button></div>
                </div>
                @if ($canVerify)
                    <div class="rounded-2xl border border-amber-300/20 bg-amber-300/10 px-5 py-3 text-center">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-amber-200">Antrean belum diverifikasi</p>
                        <p class="mt-1 text-2xl font-bold text-amber-100">{{ $pendingToday }}</p>
                        @if ($pendingOverdue > 0) <p class="text-[10px] font-bold text-rose-200">{{ $pendingOverdue }} melewati hari pengambilan</p> @endif
                    </div>
                @endif
            </div>
        </section>

        <div class="grid gap-5 xl:grid-cols-[440px_minmax(0,1fr)]">
            @if ($canTake)
                <form wire:submit="submit" class="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="font-bold text-slate-950">Catat barang yang diambil</h3>

                    @if($warehouseType === 'food')<label class="mt-4 block">
                        <span class="text-xs font-semibold text-slate-700">Rencana/batch aktif</span>
                        <select wire:model="referenceId" class="mt-1 h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                            <option value="">Pilih rencana produksi</option>
                            @forelse ($references as $reference)
                                <option value="{{ $reference['value'] }}">{{ $reference['label'] }}</option>
                            @empty
                                <option value="" disabled>Belum ada rencana produksi aktif</option>
                            @endforelse
                        </select>
                        @error('referenceId') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>@else<label class="mt-4 block"><span class="text-xs font-semibold text-slate-700">Keperluan pengambilan *</span><input wire:model="purposeReference" class="mt-1 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm" placeholder="Contoh: kebutuhan pencucian ompreng"></label>@endif

                    <div class="mt-4 space-y-3">
                        @foreach ($rows as $i => $row)
                            <div class="rounded-xl border border-slate-200 bg-slate-50 p-3" wire:key="row-{{ $i }}">
                                <select wire:model="rows.{{ $i }}.inventory_lot_id" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                                    <option value="">Pilih lot sesuai FEFO/FIFO</option>
                                    @foreach ($lots as $lot)
                                        <option value="{{ $lot->id }}">
                                            {{ $lot->ingredient?->name ?? $lot->nonFoodItem?->name ?? 'Barang' }} — {{ $lot->lot_number ?: 'tanpa batch' }} — tersedia {{ number_format((float) $lot->available_quantity, 3, ',', '.') }} {{ $lot->unit_snapshot }} — {{ ucfirst($lot->storage_type) }}
                                        </option>
                                    @endforeach
                                </select>
                                @error("rows.$i.inventory_lot_id") <span class="text-xs text-rose-600">{{ $message }}</span> @enderror

                                <div class="mt-2 grid gap-2 sm:grid-cols-2">
                                    <input wire:model="rows.{{ $i }}.quantity" type="number" step="0.001" min="0.001" class="h-10 rounded-xl border border-slate-200 px-3 text-sm" placeholder="Jumlah diambil">
                                    @if($warehouseType === 'food')<input wire:model="rows.{{ $i }}.pickup_temperature_celsius" type="number" step="0.1" class="h-10 rounded-xl border border-slate-200 px-3 text-sm" placeholder="Suhu °C jika dingin/beku">@endif
                                </div>
                                @error("rows.$i.quantity") <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                                @error("rows.$i.pickup_temperature_celsius") <span class="text-xs text-rose-600">{{ $message }}</span> @enderror

                                <label class="mt-2 block rounded-xl border border-dashed border-slate-300 bg-white p-3 text-xs">
                                    <span class="font-semibold text-slate-700">Foto barang/lot yang diambil</span>
                                    <input wire:model="rows.{{ $i }}.photo" type="file" accept="image/*" capture="environment" class="mt-2 block w-full text-xs">
                                </label>
                                @error("rows.$i.photo") <span class="text-xs text-rose-600">{{ $message }}</span> @enderror

                                @if (count($rows) > 1)
                                    <button type="button" wire:click="removeRow({{ $i }})" class="mt-2 text-xs font-bold text-rose-600">Hapus barang</button>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <button type="button" wire:click="addRow" class="mt-3 text-xs font-bold text-sky-700">+ Tambah barang/lot</button>
                    <label class="mt-4 block">
                        <span class="text-xs font-semibold text-slate-700">Catatan pengambilan bahan</span>
                        <textarea wire:model="notes" rows="2" class="mt-1 w-full rounded-xl border border-slate-200 p-3 text-sm" placeholder="Opsional"></textarea>
                        @error('notes') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <button class="mt-4 h-11 w-full rounded-xl bg-sky-600 text-xs font-bold text-white">Catat pengambilan</button>
                </form>
            @else
                <div class="h-fit rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-600">Pencatatan pengambilan tidak tersedia untuk akun ini.</div>
            @endif

            <section class="space-y-3">
                <div class="rounded-2xl border border-slate-200 bg-white p-4">
                    <h3 class="font-bold">Pemeriksaan jenis, jumlah, dan riwayat</h3>
                    @if ($canVerify)
                        <textarea wire:model="decisionNotes" class="mt-3 w-full rounded-xl border border-slate-200 p-3 text-sm" rows="2" placeholder="Catatan wajib apabila jenis atau jumlah barang tidak sesuai"></textarea>
                    @endif
                </div>

                @forelse ($records as $record)
                    <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex flex-wrap justify-between gap-3">
                            <div>
                                <p class="font-bold text-slate-900">{{ $record->withdrawal_number }}</p>
                                <p class="text-xs text-slate-500">{{ ucfirst($record->division_code) }} · {{ $record->taker?->name }} · {{ $record->reference_number_snapshot ?: $record->purpose_reference }}</p>
                                <p class="mt-1 text-[10px] text-slate-400">Diambil {{ $record->submitted_at?->translatedFormat('d M Y H:i') }}</p>
                            </div>
                            <span class="h-fit rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">{{ str($record->status)->replace('_', ' ')->title() }}</span>
                        </div>

                        <div class="mt-3 grid gap-2 sm:grid-cols-2">
                            @foreach ($record->items as $item)
                                <div class="rounded-xl bg-slate-50 p-3 text-xs">
                                    <b>{{ $item->ingredient_name_snapshot }}</b>
                                    <p class="mt-1 text-slate-500">Diminta {{ number_format((float) ($item->requested_quantity ?? $item->taken_quantity_kg), 3, ',', '.') }} {{ $item->unit_snapshot }}</p>
                                    <p class="text-slate-500">Lot {{ $item->lot_number_snapshot ?: '-' }} @if($item->pickup_temperature_celsius !== null) · {{ number_format((float) $item->pickup_temperature_celsius, 1, ',', '.') }}°C @endif</p>
                                    @if ($item->photo_path)
                                        <x-v3.documentation-button
                                            :url="Storage::disk('public')->url($item->photo_path)"
                                            :title="$item->ingredient_name_snapshot.' · '.$record->withdrawal_number"
                                            class="mt-2"
                                        />
                                    @endif
                                    @if ($canVerify && $record->status === \App\Models\WarehouseWithdrawal::WAITING)
                                        <label class="mt-3 block">
                                            <span class="font-semibold">Jumlah aktual</span>
                                            <div class="mt-1 flex items-center gap-2">
                                                <input wire:model="actualQuantities.{{ $record->id }}.{{ $item->id }}" type="number" step="0.001" min="0.001" class="h-9 w-full rounded-lg border border-slate-200 px-2">
                                                <span>{{ $item->unit_snapshot }}</span>
                                            </div>
                                        </label>
                                    @elseif ($item->actual_quantity !== null)
                                        <p class="mt-2 font-bold text-emerald-700">Aktual {{ number_format((float) $item->actual_quantity, 3, ',', '.') }} {{ $item->unit_snapshot }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>

                        @if ($record->decision_notes)
                            <p class="mt-3 rounded-xl bg-amber-50 p-3 text-xs text-amber-800">{{ $record->decision_notes }}</p>
                        @endif

                        @if ($canVerify && $record->status === \App\Models\WarehouseWithdrawal::WAITING)
                            <div class="mt-4 flex flex-wrap justify-end gap-2">
                                <button wire:click="reject({{ $record->id }})" class="rounded-xl px-3 py-2 text-xs font-bold text-rose-600">Jenis/jumlah tidak sesuai</button>
                                <button wire:click="verify({{ $record->id }})" class="rounded-xl bg-emerald-600 px-3 py-2 text-xs font-bold text-white">Sesuai & kurangi stok</button>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="rounded-2xl bg-white p-10 text-center text-sm text-slate-500">Belum ada pengambilan.</div>
                @endforelse

                {{ $records->links() }}
            </section>
        </div>

    </div>
</x-v3.shell>
