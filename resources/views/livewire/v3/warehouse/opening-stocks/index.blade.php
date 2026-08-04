<x-v3.shell :$unit :$navigation :$roleLabel title="Input Stok Awal" eyebrow="Persediaan fisik sebelum sistem digunakan">
    <div class="mx-auto max-w-[1200px] space-y-4">
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.18em] text-sky-700">Gudang</p>
                <h2 class="mt-2 text-2xl font-bold text-slate-950">Masukkan persediaan yang sudah ada</h2>
                <p class="mt-1 max-w-2xl text-sm text-slate-500">Setelah disimpan, setiap barang langsung menjadi lot aktif dan tercatat sebagai mutasi Stok Awal.</p>
            </div>
            <a wire:navigate href="{{ route('v3.warehouse.stock.index') }}" class="inline-flex h-11 items-center justify-center rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700">Lihat Kartu Stok</a>
        </div>

        @if($actionMessage)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ $actionMessage }}</div>@endif
        @error('rows')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        <form wire:submit="save" class="space-y-5">
            <section x-data="{ ingredientOptions: @js($ingredientSearchOptions) }" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <div class="grid gap-4 lg:grid-cols-[190px_1fr]">
                    <label><span class="mb-1 block text-xs font-semibold text-slate-600">Tanggal stok awal *</span><input wire:model="openingDate" type="date" max="{{ today()->toDateString() }}" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm">@error('openingDate')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label><span class="mb-1 block text-xs font-semibold text-slate-600">Catatan proses</span><input wire:model="notes" type="text" class="h-11 w-full rounded-xl border border-slate-200 px-3 text-sm" placeholder="Contoh: hasil perhitungan fisik awal gudang"></label>
                </div>
                <div x-data class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                        <div class="min-w-0"><p class="text-sm font-bold text-slate-800">Foto dokumentasi keseluruhan *</p><p class="mt-1 text-xs text-slate-500">Satu foto untuk semua barang · JPG, PNG, atau WebP · maksimal 5 MB.</p></div>
                        <input x-ref="openingPhoto" wire:model="photo" type="file" accept="image/*" capture="environment" style="display: none">
                        <button x-on:click="$refs.openingPhoto.click()" type="button" class="inline-flex h-10 shrink-0 items-center justify-center rounded-xl bg-sky-600 px-4 text-xs font-bold text-white">Pilih foto</button>
                    </div>
                    @if($photo)<p class="mt-3 rounded-lg bg-white px-3 py-2 text-xs font-semibold text-slate-600">File dipilih: {{ $photo->getClientOriginalName() }}</p>@endif
                    @error('photo')<span class="mt-2 block text-xs text-rose-600">{{ $message }}</span>@enderror
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <div class="flex items-center justify-between gap-3"><div><h3 class="font-bold text-slate-900">Daftar barang</h3><p class="mt-1 text-xs text-slate-500">Pilih barang yang tersedia atau buat barang baru langsung pada baris.</p></div><button wire:click="addRow" type="button" class="h-10 shrink-0 rounded-xl bg-sky-50 px-4 text-xs font-bold text-sky-700">+ Tambah barang</button></div>
                <div class="mt-5 space-y-4">
                    @foreach($rows as $index => $row)
                        <article wire:key="opening-stock-row-{{ $index }}" class="rounded-2xl border border-slate-200 bg-slate-50/60 p-4">
                            <div class="flex items-center justify-between gap-3"><div class="flex items-center gap-2"><span class="inline-flex size-7 items-center justify-center rounded-lg bg-[#081d3a] text-xs font-bold text-white">{{ $index + 1 }}</span><p class="text-sm font-bold text-slate-800">Data barang</p></div>@if(count($rows) > 1)<button wire:click="removeRow({{ $index }})" type="button" class="rounded-lg bg-rose-50 px-3 py-2 text-xs font-bold text-rose-600">Hapus</button>@endif</div>
                            <div class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-12">
                                <label class="md:col-span-3"><span class="mb-1 block text-xs font-semibold text-slate-600">Sumber barang</span><select wire:model.live="rows.{{ $index }}.mode" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="existing">Master Bahan</option><option value="new">Barang baru</option></select></label>
                                @if($row['mode'] === 'existing')
                                    <div x-data="{ open: false, search: @js(collect($ingredientSearchOptions)->firstWhere('id', (string) ($row['ingredient_id'] ?? ''))['label'] ?? '') }" x-on:click.outside="open = false" class="relative md:col-span-7"><label><span class="mb-1 block text-xs font-semibold text-slate-600">Cari barang *</span><input x-model="search" x-on:focus="open = true" x-on:input="open = true; $wire.set('rows.{{ $index }}.ingredient_id', '')" type="search" autocomplete="off" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Ketik nama, kode, atau satuan barang"></label><div x-show="open" x-transition class="absolute z-30 mt-1 max-h-64 w-full overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-xl"><template x-for="option in ingredientOptions.filter(item => item.label.toLowerCase().includes(search.toLowerCase().trim())).slice(0, 50)" :key="option.id"><button type="button" x-on:click="search = option.label; open = false; $wire.set('rows.{{ $index }}.ingredient_id', option.id)" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-slate-700 hover:bg-sky-50" x-text="option.label"></button></template><p x-show="ingredientOptions.filter(item => item.label.toLowerCase().includes(search.toLowerCase().trim())).length === 0" class="px-3 py-4 text-center text-xs text-slate-400">Barang tidak ditemukan</p><p x-show="ingredientOptions.filter(item => item.label.toLowerCase().includes(search.toLowerCase().trim())).length > 50" class="px-3 py-2 text-center text-[11px] text-slate-400">Ketik lebih spesifik untuk mempersempit hasil.</p></div>@error("rows.$index.ingredient_id")<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</div>
                                    <label class="md:col-span-2"><span class="mb-1 block text-xs font-semibold text-slate-600">Satuan</span><input value="{{ $ingredientUnits[$row['ingredient_id']] ?? '—' }}" disabled class="h-10 w-full rounded-xl border border-slate-200 bg-slate-100 px-3 text-sm font-semibold text-slate-500"></label>
                                @else
                                    <label class="md:col-span-4"><span class="mb-1 block text-xs font-semibold text-slate-600">Nama barang baru *</span><input wire:model="rows.{{ $index }}.new_name" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Nama barang">@error("rows.$index.new_name")<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                                    <label class="md:col-span-3"><span class="mb-1 block text-xs font-semibold text-slate-600">Kategori *</span><select wire:model="rows.{{ $index }}.new_category" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">@foreach($categories as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                                    <label class="md:col-span-2"><span class="mb-1 block text-xs font-semibold text-slate-600">Satuan *</span><select wire:model="rows.{{ $index }}.measurement_unit_id" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="">Pilih satuan</option>@foreach($measurementUnits as $measurementUnit)<option value="{{ $measurementUnit->id }}">{{ $measurementUnit->symbol ?: $measurementUnit->code }}</option>@endforeach</select></label>
                                @endif
                                <label class="md:col-span-2"><span class="mb-1 block text-xs font-semibold text-slate-600">Jumlah *</span><input wire:model="rows.{{ $index }}.quantity" type="number" min="0" step="0.0001" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="0">@error("rows.$index.quantity")<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                                <label class="md:col-span-3"><span class="mb-1 block text-xs font-semibold text-slate-600">Penyimpanan *</span><select wire:model="rows.{{ $index }}.storage_type" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">@foreach($storageTypes as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                                <label class="md:col-span-3"><span class="mb-1 block text-xs font-semibold text-slate-600">Lokasi/rak</span><input wire:model="rows.{{ $index }}.location_name" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Gudang Utama"></label>
                                <label class="md:col-span-2"><span class="mb-1 block text-xs font-semibold text-slate-600">Lot/batch</span><input wire:model="rows.{{ $index }}.lot_number" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Opsional"></label>
                                <label class="md:col-span-2"><span class="mb-1 block text-xs font-semibold text-slate-600">Kedaluwarsa</span><input wire:model="rows.{{ $index }}.expired_date" type="date" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">@error("rows.$index.expired_date")<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                                <label class="md:col-span-12"><span class="mb-1 block text-xs font-semibold text-slate-600">Catatan kondisi</span><input wire:model="rows.{{ $index }}.condition_notes" class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Opsional, misalnya kondisi kemasan atau hasil pengecekan"></label>
                            </div>
                        </article>
                    @endforeach
                </div>
                <div class="mt-5 flex flex-col justify-between gap-3 border-t border-slate-100 pt-4 sm:flex-row sm:items-center"><p class="text-xs text-slate-500"><span class="font-bold text-slate-800">{{ count($rows) }} barang</span> akan langsung masuk ke Kartu Stok.</p><button type="submit" wire:loading.attr="disabled" class="h-11 rounded-xl bg-emerald-600 px-6 text-sm font-bold text-white disabled:opacity-50">Simpan dan Aktifkan Stok</button></div>
            </section>
        </form>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
            <h3 class="font-bold text-slate-900">Riwayat input stok awal</h3>
            <div class="mt-4 space-y-3">
                @forelse($recentOpenings as $opening)
                    <article class="flex flex-col justify-between gap-3 rounded-xl border border-slate-200 p-4 sm:flex-row sm:items-center"><div><p class="text-sm font-bold text-slate-800">{{ $opening->opening_number }}</p><p class="mt-1 text-xs text-slate-500">{{ $opening->opening_date->translatedFormat('d M Y') }} · {{ $opening->items->count() }} barang · {{ $opening->creator->name }}</p></div><x-v3.documentation-button :url="\Illuminate\Support\Facades\Storage::disk('public')->url($opening->photo_path)" :title="'Dokumentasi '.$opening->opening_number" label="Lihat dokumentasi" /></article>
                @empty
                    <p class="rounded-xl border border-dashed border-slate-200 p-8 text-center text-sm text-slate-400">Belum ada input stok awal.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-v3.shell>
