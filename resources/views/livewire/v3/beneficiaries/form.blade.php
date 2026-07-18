<x-v3.shell :$unit :$navigation :$roleLabel :title="$beneficiaryId ? 'Ubah Penerima' : 'Tambah Penerima'" eyebrow="Data master">
    <div class="mx-auto max-w-5xl space-y-5">
        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <div>
                <a wire:navigate href="{{ route('v3.beneficiaries.index') }}" class="inline-flex items-center gap-2 text-xs font-bold text-sky-700 hover:text-sky-900">
                    <x-v3.icon name="arrow-left" class="size-4" /> Kembali ke daftar
                </a>
                <h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950">{{ $beneficiaryId ? 'Perbarui data penerima' : 'Tambahkan penerima baru' }}</h2>
                <p class="mt-1 text-sm text-slate-500">Data lembaga, kategori, periode layanan, dan alergi tersimpan pada satu sumber data operasional.</p>
            </div>
            <span class="self-start rounded-full bg-sky-50 px-3 py-1 text-[10px] font-bold uppercase tracking-wider text-sky-700 ring-1 ring-sky-100">Form native V3</span>
        </div>

        <form wire:submit="save" class="space-y-5">
            @if ($errors->has('form'))
                <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $errors->first('form') }}</div>
            @endif

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="border-b border-slate-100 pb-4">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">01 · Asal penerima</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Lembaga dan kategori</h3>
                </div>
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Jenis lembaga <b class="text-rose-600">*</b></span>
                        <select wire:model.live="beneficiaryableType" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                            <option value="">Pilih jenis lembaga</option>
                            <option value="school">Sekolah</option>
                            <option value="posyandu">Posyandu</option>
                        </select>
                        @error('beneficiaryableType') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Sekolah/Posyandu <b class="text-rose-600">*</b></span>
                        <select wire:model="beneficiaryableId" @disabled($beneficiaryableType === '') class="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none disabled:cursor-not-allowed disabled:opacity-50 focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                            <option value="">Pilih lembaga</option>
                            @foreach ($institutions as $id => $institutionName)
                                <option value="{{ $id }}">{{ $institutionName }}</option>
                            @endforeach
                        </select>
                        @error('beneficiaryableId') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Kategori penerima <b class="text-rose-600">*</b></span>
                        <select wire:model="beneficiaryCategoryId" class="h-12 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                            <option value="">Pilih kategori penerima</option>
                            @foreach ($categories as $id => $categoryName)
                                <option value="{{ $id }}">{{ $categoryName }}</option>
                            @endforeach
                        </select>
                        @error('beneficiaryCategoryId') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="border-b border-slate-100 pb-4">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">02 · Identitas</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Data penerima dan layanan</h3>
                </div>
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    @php($inputClass = 'h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-sky-400 focus:ring-4 focus:ring-sky-100')
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Nomor induk/registrasi <b class="text-rose-600">*</b></span>
                        <input wire:model="externalId" type="text" class="{{ $inputClass }}" placeholder="NISN, NIK, atau nomor registrasi">
                        @error('externalId') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Nama penerima <b class="text-rose-600">*</b></span>
                        <input wire:model="name" type="text" class="{{ $inputClass }}" placeholder="Nama lengkap">
                        @error('name') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Kelas/kelompok</span>
                        <input wire:model="groupName" type="text" class="{{ $inputClass }}" placeholder="Contoh: Kelas 4 atau Kelompok A">
                        @error('groupName') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Nama orang tua/wali</span>
                        <input wire:model="parentName" type="text" class="{{ $inputClass }}" placeholder="Nama orang tua atau wali">
                        @error('parentName') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Posisi penerima</span>
                        <input wire:model="recipientPosition" type="text" class="{{ $inputClass }}" placeholder="Contoh: 1">
                        @error('recipientPosition') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Tanggal lahir</span>
                        <input wire:model="birthDate" type="date" class="{{ $inputClass }}">
                        @error('birthDate') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Jenis kelamin</span>
                        <select wire:model="gender" class="{{ $inputClass }}">
                            <option value="">Belum ditentukan</option>
                            <option value="male">Laki-laki</option>
                            <option value="female">Perempuan</option>
                        </select>
                        @error('gender') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block sm:col-span-2">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Alamat</span>
                        <textarea wire:model="address" rows="3" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none placeholder:text-slate-400 focus:border-sky-400 focus:ring-4 focus:ring-sky-100" placeholder="Alamat penerima"></textarea>
                        @error('address') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Mulai menerima</span>
                        <input wire:model="startDate" type="date" class="{{ $inputClass }}">
                        @error('startDate') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="mb-2 block text-sm font-semibold text-slate-700">Selesai menerima</span>
                        <input wire:model="endDate" type="date" class="{{ $inputClass }}">
                        @error('endDate') <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 sm:col-span-2">
                        <input wire:model="isActive" type="checkbox" class="size-4 rounded border-slate-300 text-sky-700 focus:ring-sky-500">
                        <span><strong class="block text-sm text-slate-800">Penerima aktif</strong><small class="text-xs text-slate-500">Penerima aktif akan masuk saat snapshot periode dibuat.</small></span>
                    </label>
                </div>
            </section>

            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="border-b border-slate-100 pb-4">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-amber-700">03 · Keamanan pangan</p>
                    <h3 class="mt-1 text-lg font-bold text-slate-950">Kebutuhan khusus dan alergi</h3>
                </div>
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <label class="block"><span class="mb-2 block text-sm font-semibold text-slate-700">Catatan alergi umum</span><textarea wire:model="allergyNotes" rows="3" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100"></textarea></label>
                    <label class="block"><span class="mb-2 block text-sm font-semibold text-slate-700">Kebutuhan khusus</span><textarea wire:model="specialNeeds" rows="3" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100"></textarea></label>
                    <label class="block sm:col-span-2"><span class="mb-2 block text-sm font-semibold text-slate-700">Catatan lain</span><textarea wire:model="notes" rows="3" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100"></textarea></label>
                </div>

                <div class="mt-6 flex items-center justify-between border-t border-slate-100 pt-5">
                    <div><h4 class="text-sm font-bold text-slate-800">Alergen terstruktur</h4><p class="mt-0.5 text-xs text-slate-500">Dipakai Ahli Gizi untuk pemeriksaan konflik menu.</p></div>
                    <button type="button" wire:click="addAllergen" class="rounded-xl bg-sky-50 px-3 py-2 text-xs font-bold text-sky-700 ring-1 ring-sky-100">Tambah alergen</button>
                </div>
                <div class="mt-4 space-y-3">
                    @forelse ($allergens as $index => $allergen)
                        <div wire:key="allergen-row-{{ $index }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50/60 p-4 sm:grid-cols-[1fr_180px_auto]">
                            <label><span class="mb-1.5 block text-xs font-semibold text-slate-600">Alergen</span><select wire:model="allergens.{{ $index }}.allergen_id" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="">Pilih alergen</option>@foreach ($allergenOptions as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>@error("allergens.{$index}.allergen_id") <span class="mt-1 block text-xs text-rose-600">{{ $message }}</span> @enderror</label>
                            <label><span class="mb-1.5 block text-xs font-semibold text-slate-600">Keparahan</span><select wire:model="allergens.{{ $index }}.severity" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"><option value="unknown">Belum diketahui</option><option value="mild">Ringan</option><option value="moderate">Sedang</option><option value="severe">Berat</option></select></label>
                            <button type="button" wire:click="removeAllergen({{ $index }})" class="mt-6 grid size-11 place-items-center rounded-xl text-rose-700 transition hover:bg-rose-50" aria-label="Hapus alergen"><x-v3.icon name="trash" class="size-4" /></button>
                            <label class="sm:col-span-3"><span class="mb-1.5 block text-xs font-semibold text-slate-600">Reaksi atau catatan medis</span><textarea wire:model="allergens.{{ $index }}.reaction_notes" rows="2" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"></textarea></label>
                        </div>
                    @empty
                        <div class="rounded-2xl border border-dashed border-slate-200 p-6 text-center text-xs text-slate-500">Belum ada alergen terstruktur.</div>
                    @endforelse
                </div>
            </section>

            <div class="flex flex-col-reverse justify-between gap-3 sm:flex-row sm:items-center">
                @if ($beneficiaryId && (auth()->user()->is_super_admin || auth()->user()->can('beneficiaries.delete')))
                    <button type="button" wire:click="delete" wire:confirm="Hapus permanen penerima ini? Data tidak dapat dipulihkan." class="inline-flex h-12 items-center justify-center gap-2 rounded-xl px-4 text-sm font-bold text-rose-700 transition hover:bg-rose-50"><x-v3.icon name="trash" class="size-4" /> Hapus permanen</button>
                @else
                    <span></span>
                @endif
                <div class="flex gap-3">
                    <a wire:navigate href="{{ route('v3.beneficiaries.index') }}" class="inline-flex h-12 items-center justify-center rounded-xl border border-slate-200 px-5 text-sm font-bold text-slate-600 hover:bg-slate-50">Batal</a>
                    <button type="submit" wire:loading.attr="disabled" class="inline-flex h-12 min-w-40 items-center justify-center rounded-xl bg-[#081d3a] px-5 text-sm font-bold text-white shadow-lg shadow-slate-900/15 disabled:opacity-60">
                        <span wire:loading.remove wire:target="save">Simpan data</span><span wire:loading wire:target="save">Menyimpan...</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</x-v3.shell>
