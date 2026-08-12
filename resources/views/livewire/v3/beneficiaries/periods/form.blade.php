<div>
<x-v3.shell :$unit :$navigation :$roleLabel :title="$periodId ? 'Ubah Periode Penerima' : 'Buat Periode Penerima'" eyebrow="Periode penerima 14 hari">
    <div class="mx-auto max-w-6xl space-y-5">
        <div>
            <a wire:navigate href="{{ route('v3.beneficiary-periods.index') }}" class="inline-flex items-center gap-2 text-xs font-bold text-sky-700 hover:text-sky-900"><x-v3.icon name="arrow-left" class="size-4" /> Kembali ke data penerima</a>
            <h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950">{{ $periodId ? 'Perbarui draft periode penerima' : 'Buat periode penerima 14 hari' }}</h2>
            <p class="mt-1 text-sm text-slate-500">Pilih sumber data dari master penerima bernama atau masukkan jumlah per kategori secara manual.</p>
        </div>

        <form wire:submit="save" class="space-y-5">
            @error('form') <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div> @enderror

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-start gap-3 border-b border-slate-100 pb-5"><span class="grid size-11 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-700 ring-1 ring-sky-100"><x-v3.icon name="calendar" class="size-5" /></span><div><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Periode berlaku</p><h3 class="mt-1 text-lg font-bold text-slate-950">Draft periode 14 hari</h3><p class="mt-1 text-xs text-slate-500">Setelah disimpan, periode diajukan, disetujui, lalu diaktifkan.</p></div></div>
                @php($inputClass = 'h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100')
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <label><span class="mb-2 block text-sm font-semibold text-slate-700">Kode data <b class="text-rose-600">*</b></span><input wire:model="code" class="{{ $inputClass }}">@error('code')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label><span class="mb-2 block text-sm font-semibold text-slate-700">Nama periode <b class="text-rose-600">*</b></span><input wire:model="name" class="{{ $inputClass }}">@error('name')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label><span class="mb-2 block text-sm font-semibold text-slate-700">Tanggal mulai <b class="text-rose-600">*</b></span><input wire:model.live="startDate" type="date" class="{{ $inputClass }}">@error('startDate')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label><span class="mb-2 block text-sm font-semibold text-slate-700">Tanggal selesai</span><input wire:model="endDate" type="date" readonly class="{{ $inputClass }} bg-slate-50">@error('endDate')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label class="sm:col-span-2"><span class="mb-2 block text-sm font-semibold text-slate-700">Catatan</span><textarea wire:model="notes" rows="3" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100" placeholder="Opsional"></textarea>@error('notes')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Sumber data</p><h3 class="mt-1 text-lg font-bold text-slate-950">Pilih cara mengisi penerima</h3></div>
                <div class="mt-4 grid gap-3 md:grid-cols-2">
                    <label class="cursor-pointer rounded-2xl border p-4 transition {{ $inputMode === 'master' ? 'border-sky-400 bg-sky-50 ring-2 ring-sky-100' : 'border-slate-200 hover:border-sky-200' }}">
                        <span class="flex items-start gap-3">
                            <input wire:model.live="inputMode" type="radio" value="master" class="mt-1 size-4 border-slate-300 text-sky-600 focus:ring-sky-500">
                            <span><span class="block text-sm font-bold text-slate-900">Otomatis dari master penerima</span><span class="mt-1 block text-xs leading-5 text-slate-500">Sistem mengambil snapshot penerima aktif beserta nama, instansi, kelas, kelompok, dan kategori porsinya.</span></span>
                        </span>
                    </label>
                    <label class="cursor-pointer rounded-2xl border p-4 transition {{ $inputMode === 'manual' ? 'border-sky-400 bg-sky-50 ring-2 ring-sky-100' : 'border-slate-200 hover:border-sky-200' }}">
                        <span class="flex items-start gap-3">
                            <input wire:model.live="inputMode" type="radio" value="manual" class="mt-1 size-4 border-slate-300 text-sky-600 focus:ring-sky-500">
                            <span><span class="block text-sm font-bold text-slate-900">Input jumlah manual</span><span class="mt-1 block text-xs leading-5 text-slate-500">Gunakan bila klien belum memiliki data penerima berdasarkan nama. Isi jumlah per sekolah/Posyandu dan kategori.</span></span>
                        </span>
                    </label>
                </div>
                @error('inputMode')<span class="mt-2 block text-xs text-rose-600">{{ $message }}</span>@enderror

                @if($inputMode === 'manual' && (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.copy')))
                    <div class="mt-5 rounded-2xl border border-sky-200 bg-sky-50 p-4">
                        <p class="text-xs font-bold uppercase tracking-[.14em] text-sky-700">Salin periode sebelumnya</p>
                        <p class="mt-1 text-xs leading-5 text-slate-600">Pilih periode sumber untuk mengisi seluruh sekolah/Posyandu dan jumlah per kategori secara otomatis.</p>
                        <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                            <select wire:model="sourcePeriodId" class="h-11 min-w-0 flex-1 rounded-xl border border-sky-200 bg-white px-3 text-sm text-slate-800">
                                <option value="">Pilih periode sumber</option>
                                @foreach($previousPeriods as $source)
                                    <option value="{{ $source->id }}">{{ $source->name }} · {{ $source->start_date->translatedFormat('d M') }}–{{ $source->end_date->translatedFormat('d M Y') }} · {{ number_format($source->active_members, 0, ',', '.') }} penerima</option>
                                @endforeach
                            </select>
                            <button type="button" wire:click="copyPreviousPeriod" wire:loading.attr="disabled" wire:target="copyPreviousPeriod" class="h-11 shrink-0 rounded-xl bg-sky-700 px-4 text-xs font-bold text-white disabled:opacity-60">
                                <span wire:loading.remove wire:target="copyPreviousPeriod">Salin data</span>
                                <span wire:loading wire:target="copyPreviousPeriod">Menyalin...</span>
                            </button>
                        </div>
                        @error('sourcePeriodId')<span class="mt-2 block text-xs text-rose-600">{{ $message }}</span>@enderror
                        @if($copyMessage)<div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700">{{ $copyMessage }}</div>@endif
                    </div>
                @endif
            </section>

            @if ($inputMode === 'manual')
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
            @else
                <section class="rounded-2xl border border-sky-200 bg-sky-50 p-5 text-sm leading-6 text-sky-900">
                    <strong>Snapshot otomatis:</strong> saat draft disimpan, sistem menyalin seluruh penerima aktif dari modul Penerima Manfaat. Perubahan pada master setelah itu tidak mengubah periode ini sampai tombol “Perbarui snapshot” digunakan.
                </section>
            @endif

            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-xs leading-5 text-emerald-800"><strong>Sumber modul berikutnya:</strong> setelah periode disetujui dan diaktifkan, jumlahnya digunakan untuk penyusunan menu, perhitungan porsi, kebutuhan bahan, dan Rencana Lapangan.</div>

            <div class="flex flex-col-reverse justify-between gap-3 sm:flex-row sm:items-center">
                @if ($periodId && (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.delete')))
                    <button type="button" wire:click="delete" wire:confirm="Hapus data jumlah penerima ini?" class="inline-flex h-12 items-center justify-center gap-2 rounded-xl px-4 text-sm font-bold text-rose-700 hover:bg-rose-50"><x-v3.icon name="trash" class="size-4" /> Hapus data</button>
                @else<span></span>@endif
                <div class="flex gap-3"><a wire:navigate href="{{ route('v3.beneficiary-periods.index') }}" class="inline-flex h-12 items-center rounded-xl border border-slate-200 px-5 text-sm font-bold text-slate-600 hover:bg-slate-50">Batal</a><button type="submit" wire:loading.attr="disabled" class="inline-flex h-12 min-w-44 items-center justify-center rounded-xl bg-[#081d3a] px-5 text-sm font-bold text-white shadow-lg disabled:opacity-60"><span wire:loading.remove wire:target="save">Simpan draft</span><span wire:loading wire:target="save">Menyimpan...</span></button></div>
            </div>
        </form>
    </div>
</x-v3.shell>
</div>
