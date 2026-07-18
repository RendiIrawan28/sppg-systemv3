<x-v3.shell :$unit :$navigation :$roleLabel title="Impor Penerima" eyebrow="Data master">
    <div class="mx-auto max-w-6xl space-y-5">
        <div>
            <a wire:navigate href="{{ route('v3.beneficiaries.index') }}" class="inline-flex items-center gap-2 text-xs font-bold text-sky-700 hover:text-sky-900"><x-v3.icon name="arrow-left" class="size-4" /> Kembali ke daftar</a>
            <h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950">Impor Excel atau CSV</h2>
            <p class="mt-1 text-sm text-slate-500">Gunakan template resmi, periksa hasil validasi, lalu jalankan impor ke lembaga yang dipilih.</p>
        </div>

        @if ($importResult)
            <section class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5">
                <div class="flex items-start gap-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-emerald-100 text-emerald-700"><x-v3.icon name="check-badge" class="size-5" /></span>
                    <div><h3 class="text-sm font-bold text-emerald-800">Impor selesai diproses</h3><p class="mt-1 text-xs text-emerald-700">Ditambahkan {{ $importResult['inserted'] }}, diperbarui {{ $importResult['updated'] }}, dan gagal {{ $importResult['invalid'] }} dari {{ $importResult['total'] }} baris.</p></div>
                </div>
            </section>
        @endif

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
            <form wire:submit="preview" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-start gap-3 border-b border-slate-100 pb-5">
                    <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-700 ring-1 ring-sky-100"><x-v3.icon name="upload" class="size-5" /></span>
                    <div><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Langkah 1</p><h3 class="mt-1 text-lg font-bold text-slate-950">Pilih tujuan dan file</h3></div>
                </div>

                <div class="mt-5 space-y-5">
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Sekolah atau posyandu tujuan <b class="text-rose-600">*</b></span>
                        <select wire:model.live="institution" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                            <option value="">Pilih lembaga tujuan</option>
                            @if ($schools->isNotEmpty())<optgroup label="Sekolah">@foreach ($schools as $school)<option value="school:{{ $school->id }}">{{ $school->name }}</option>@endforeach</optgroup>@endif
                            @if ($posyandus->isNotEmpty())<optgroup label="Posyandu">@foreach ($posyandus as $posyandu)<option value="posyandu:{{ $posyandu->id }}">{{ $posyandu->name }}</option>@endforeach</optgroup>@endif
                        </select>
                        @error('institution') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>

                    @if (str_starts_with($institution, 'school:'))
                        <label class="block">
                            <span class="mb-2 block text-sm font-semibold text-slate-700">Kategori default untuk file lama</span>
                            <select wire:model="defaultSchoolCategoryCode" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                                <option value="">Otomatis dari jenjang dan kelas</option>
                                @foreach ($schoolCategories as $category)<option value="{{ $category->code }}">{{ $category->name }}</option>@endforeach
                            </select>
                            <span class="mt-1.5 block text-xs leading-5 text-slate-500">Gunakan hanya jika seluruh isi file lama berasal dari satu kategori.</span>
                            @error('defaultSchoolCategoryCode') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                        </label>
                    @endif

                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">File data <b class="text-rose-600">*</b></span>
                        <div class="rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50/60 p-5">
                            <input wire:model="file" type="file" accept=".xlsx,.xls,.csv" class="block w-full text-sm text-slate-600 file:mr-4 file:rounded-xl file:border-0 file:bg-[#081d3a] file:px-4 file:py-2.5 file:text-xs file:font-bold file:text-white">
                            <p class="mt-3 text-xs text-slate-500">Format XLSX, XLS, atau CSV. Ukuran maksimal 5 MB.</p>
                            <p wire:loading wire:target="file" class="mt-2 text-xs font-semibold text-sky-700">Mengunggah file...</p>
                        </div>
                        @error('file') <span class="mt-2 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>

                    <label class="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <input wire:model="updateExisting" type="checkbox" class="mt-0.5 size-4 rounded border-slate-300 text-sky-700 focus:ring-sky-500">
                        <span><strong class="block text-sm text-slate-800">Perbarui data yang sudah ada</strong><small class="mt-1 block text-xs leading-5 text-slate-500">Pencocokan menggunakan nomor induk pada lembaga tujuan.</small></span>
                    </label>

                    <button type="submit" wire:loading.attr="disabled" class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#081d3a] px-5 text-sm font-bold text-white shadow-lg disabled:opacity-60">
                        <span wire:loading.remove wire:target="preview">Periksa file</span><span wire:loading wire:target="preview">Memvalidasi...</span>
                    </button>
                </div>
            </form>

            <aside class="space-y-5">
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Template resmi</p>
                    <h3 class="mt-1 text-base font-bold text-slate-950">Unduh sesuai jenis lembaga</h3>
                    <p class="mt-2 text-xs leading-5 text-slate-500">Header dan contoh pengisian sudah sesuai parser impor aplikasi.</p>
                    <div class="mt-4 grid gap-2">
                        <a href="{{ route('beneficiaries.template', 'school') }}" class="flex items-center justify-between rounded-xl border border-slate-200 px-4 py-3 text-xs font-bold text-slate-700 hover:bg-slate-50"><span>Template sekolah</span><x-v3.icon name="arrow-up-right" class="size-4" /></a>
                        <a href="{{ route('beneficiaries.template', 'posyandu') }}" class="flex items-center justify-between rounded-xl border border-slate-200 px-4 py-3 text-xs font-bold text-slate-700 hover:bg-slate-50"><span>Template posyandu</span><x-v3.icon name="arrow-up-right" class="size-4" /></a>
                    </div>
                </section>

                @if ($previewResult)
                    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between"><div><p class="text-[10px] font-bold uppercase tracking-[.16em] text-emerald-700">Langkah 2</p><h3 class="mt-1 text-base font-bold text-slate-950">Hasil pemeriksaan</h3></div><span class="rounded-full bg-emerald-50 px-2.5 py-1 text-[10px] font-bold text-emerald-700">Siap</span></div>
                        <div class="mt-4 grid grid-cols-2 gap-2">
                            @foreach ([['Total', $previewResult['total'], 'bg-slate-50 text-slate-600'], ['Valid', $previewResult['valid'], 'bg-emerald-50 text-emerald-700'], ['Tidak valid', $previewResult['invalid'], 'bg-rose-50 text-rose-700'], ['Sudah ada', $previewResult['existing'], 'bg-amber-50 text-amber-700']] as [$label, $value, $toneClass])
                                <div class="rounded-xl p-3 {{ $toneClass }}"><p class="text-[10px] font-semibold">{{ $label }}</p><p class="mt-1 text-xl font-bold text-slate-950">{{ $value }}</p></div>
                            @endforeach
                        </div>
                        @if ($previewResult['errors'])
                            <div class="mt-4 max-h-48 space-y-2 overflow-y-auto rounded-xl border border-rose-100 bg-rose-50 p-3">
                                @foreach (array_slice($previewResult['errors'], 0, 12) as $error)
                                    <p class="text-xs leading-5 text-rose-700"><strong>Baris {{ $error['row'] ?? '?' }}:</strong> {{ implode(' ', $error['messages'] ?? []) }}</p>
                                @endforeach
                            </div>
                        @endif
                        <button type="button" wire:click="import" wire:loading.attr="disabled" wire:confirm="Jalankan impor data yang sudah diperiksa?" class="mt-5 inline-flex h-12 w-full items-center justify-center rounded-xl bg-emerald-600 px-5 text-sm font-bold text-white shadow-lg shadow-emerald-900/15 disabled:opacity-60">
                            <span wire:loading.remove wire:target="import">Jalankan impor</span><span wire:loading wire:target="import">Memproses...</span>
                        </button>
                    </section>
                @else
                    <section class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center"><x-v3.icon name="clipboard" class="mx-auto size-6 text-slate-400" /><p class="mt-3 text-sm font-bold text-slate-700">Preview belum tersedia</p><p class="mt-1 text-xs text-slate-500">Pilih tujuan dan file, lalu klik Periksa file.</p></section>
                @endif
            </aside>
        </div>
    </div>
</x-v3.shell>
