<x-v3.shell :$unit :$navigation :$roleLabel :title="$periodId ? 'Ubah Jumlah Penerima' : 'Input Jumlah Penerima'" eyebrow="Jumlah per tujuan dan kategori">
    <div class="mx-auto max-w-6xl space-y-5">
        <div>
            <a wire:navigate href="{{ route('v3.beneficiary-periods.index') }}" class="inline-flex items-center gap-2 text-xs font-bold text-sky-700 hover:text-sky-900"><x-v3.icon name="arrow-left" class="size-4" /> Kembali ke data penerima</a>
            <h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950">{{ $periodId ? 'Perbarui jumlah penerima' : 'Masukkan jumlah penerima' }}</h2>
            <p class="mt-1 text-sm text-slate-500">Isi angka per sekolah atau Posyandu. Nama masing-masing penerima tidak diperlukan.</p>
        </div>

        <form wire:submit="save" class="space-y-5">
            @error('form') <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div> @enderror

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-start gap-3 border-b border-slate-100 pb-5"><span class="grid size-11 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-700 ring-1 ring-sky-100"><x-v3.icon name="calendar" class="size-5" /></span><div><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Periode berlaku</p><h3 class="mt-1 text-lg font-bold text-slate-950">Data langsung aktif setelah disimpan</h3></div></div>
                @php($inputClass = 'h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100')
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <label><span class="mb-2 block text-sm font-semibold text-slate-700">Kode data <b class="text-rose-600">*</b></span><input wire:model="code" class="{{ $inputClass }}">@error('code')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label><span class="mb-2 block text-sm font-semibold text-slate-700">Nama periode <b class="text-rose-600">*</b></span><input wire:model="name" class="{{ $inputClass }}">@error('name')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label><span class="mb-2 block text-sm font-semibold text-slate-700">Tanggal mulai <b class="text-rose-600">*</b></span><input wire:model.live="startDate" type="date" class="{{ $inputClass }}">@error('startDate')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label><span class="mb-2 block text-sm font-semibold text-slate-700">Tanggal selesai</span><input wire:model="endDate" type="date" readonly class="{{ $inputClass }} bg-slate-50">@error('endDate')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label class="sm:col-span-2"><span class="mb-2 block text-sm font-semibold text-slate-700">Catatan</span><textarea wire:model="notes" rows="3" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100" placeholder="Opsional"></textarea>@error('notes')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
                <div class="flex flex-col justify-between gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-center">
                    <div><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Jumlah penerima</p><h3 class="mt-1 text-lg font-bold text-slate-950">Sekolah dan Posyandu</h3><p class="mt-1 text-xs text-slate-500">Kolom kosong dianggap nol.</p></div>
                    <button type="button" wire:click="addDestination" class="inline-flex h-9 items-center justify-center rounded-lg bg-sky-50 px-3 text-xs font-bold text-sky-700 ring-1 ring-sky-100">+ Tambah tujuan</button>
                </div>

                <div class="mt-4 space-y-3">
                    @foreach ($destinations as $destinationIndex => $destination)
                        <article wire:key="recipient-destination-{{ $destinationIndex }}" class="rounded-xl border border-slate-200 bg-slate-50/50 p-3">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
                                <label class="min-w-0 flex-1"><span class="mb-1.5 block text-xs font-semibold text-slate-600">Sekolah atau Posyandu <b class="text-rose-600">*</b></span>
                                    <select wire:model="destinations.{{ $destinationIndex }}.destination_key" class="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm">
                                        <option value="">Pilih tujuan</option>
                                        <optgroup label="Sekolah">@foreach($destinationOptions as $key => $option) @if($option['type'] === 'school')<option value="{{ $key }}">{{ $option['label'] }}</option>@endif @endforeach</optgroup>
                                        <optgroup label="Posyandu">@foreach($destinationOptions as $key => $option) @if($option['type'] === 'posyandu')<option value="{{ $key }}">{{ $option['label'] }}</option>@endif @endforeach</optgroup>
                                    </select>
                                    @error("destinations.{$destinationIndex}.destination_key")<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                                </label>
                                <button type="button" wire:click="removeDestination({{ $destinationIndex }})" class="h-10 rounded-lg px-3 text-xs font-bold text-rose-600 hover:bg-rose-50">Hapus tujuan</button>
                            </div>

                            <div class="mt-3 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                                @foreach ($categories as $category)
                                    <label class="flex min-h-14 items-center gap-2 rounded-lg border border-slate-200 bg-white p-2.5">
                                        <span class="min-w-0 flex-1">
                                            <span class="block text-xs font-semibold leading-4 text-slate-700">{{ $category->name }}</span>
                                            <span class="block text-[10px] leading-4 text-slate-400">{{ $category->portion_size === 'large' ? 'Porsi besar' : 'Porsi kecil' }}</span>
                                        </span>
                                        <input wire:model="destinations.{{ $destinationIndex }}.counts.{{ $category->id }}" type="number" min="0" step="1" inputmode="numeric" placeholder="0" aria-label="Jumlah {{ $category->name }}" class="h-9 !w-20 shrink-0 rounded-lg border border-slate-200 bg-white px-2 text-right text-sm font-bold">
                                        @error("destinations.{$destinationIndex}.counts.{$category->id}")<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                                    </label>
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>
            </section>

            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-xs leading-5 text-emerald-800"><strong>Langsung digunakan:</strong> setelah disimpan, jumlah ini menjadi sumber penyusunan menu, perhitungan porsi, kebutuhan bahan, dan Rencana Lapangan pada rentang tanggal yang dipilih.</div>

            <div class="flex flex-col-reverse justify-between gap-3 sm:flex-row sm:items-center">
                @if ($periodId && (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.delete')))
                    <button type="button" wire:click="delete" wire:confirm="Hapus data jumlah penerima ini?" class="inline-flex h-12 items-center justify-center gap-2 rounded-xl px-4 text-sm font-bold text-rose-700 hover:bg-rose-50"><x-v3.icon name="trash" class="size-4" /> Hapus data</button>
                @else<span></span>@endif
                <div class="flex gap-3"><a wire:navigate href="{{ route('v3.beneficiary-periods.index') }}" class="inline-flex h-12 items-center rounded-xl border border-slate-200 px-5 text-sm font-bold text-slate-600 hover:bg-slate-50">Batal</a><button type="submit" wire:loading.attr="disabled" class="inline-flex h-12 min-w-44 items-center justify-center rounded-xl bg-[#081d3a] px-5 text-sm font-bold text-white shadow-lg disabled:opacity-60"><span wire:loading.remove wire:target="save">Simpan & aktifkan</span><span wire:loading wire:target="save">Menyimpan...</span></button></div>
            </div>
        </form>
    </div>
</x-v3.shell>
