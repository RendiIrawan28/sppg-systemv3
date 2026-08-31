<x-v3.shell :$unit :$navigation :$roleLabel title="Perencanaan Menu" eyebrow="Matriks menu & porsi">
    <div class="mx-auto max-w-[1600px] space-y-5">
        @if ($actionMessage)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ $actionMessage }}</div>@endif
        @error('action')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        <section class="relative overflow-hidden rounded-[28px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7">
            <div class="absolute -right-24 -top-32 size-96 rounded-full bg-cyan-300/10"></div>
            <div class="relative flex flex-col justify-between gap-6 xl:flex-row xl:items-end">
                <div><span class="inline-flex rounded-full bg-cyan-300/10 px-3 py-1 text-[10px] font-bold uppercase tracking-[.16em] text-cyan-200 ring-1 ring-cyan-300/20">Entry point tunggal</span>
                    <h2 class="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">Susun menu harian dari snapshot penerima.</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">Porsi kecil, besar, dan buffer mengikuti periode penerima. Komponen menu dapat diisi langsung atau ditempel dari Excel.</p>
                </div>
                <div class="relative flex flex-wrap gap-2"><a wire:navigate href="{{ route('v3.nutrition.requirements.index') }}" class="inline-flex h-11 items-center gap-2 rounded-xl border border-white/15 bg-white/10 px-4 text-xs font-bold text-white hover:bg-white/15"><x-v3.icon name="calculator" class="size-4" /> Kebutuhan bahan</a><a wire:navigate href="{{ route('v3.nutrition.standards') }}" class="inline-flex h-11 items-center gap-2 rounded-xl border border-white/15 bg-white/10 px-4 text-xs font-bold text-white hover:bg-white/15"><x-v3.icon name="settings" class="size-4" /> Standar & satuan</a></div>
            </div>
        </section>

        <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
                    <label class="block flex-1"><span class="mb-2 block text-xs font-bold text-slate-600">Periode penerima</span><select wire:model.live="planningPeriodId" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700 outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                            <option value="">Pilih periode penerima</option>@foreach ($periods as $period)<option value="{{ $period->id }}">{{ $period->code }} — {{ $period->name }}</option>@endforeach
                        </select>@error('planningPeriodId')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label class="block w-full sm:w-36"><span class="mb-2 block text-xs font-bold text-slate-600">Buffer porsi</span>
                        <div class="relative"><input wire:model.live.debounce.400ms="bufferPercent" type="number" min="0" max="20" step="0.01" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 pr-8 text-sm"><span class="absolute inset-y-0 right-3 grid place-items-center text-xs text-slate-400">%</span></div>@error('bufferPercent')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                    </label>
                    @if ($cycle?->isEditable() && (auth()->user()->is_super_admin || auth()->user()->can('menus.update')))<button wire:click="refreshSnapshot" class="h-11 rounded-xl bg-sky-50 px-4 text-xs font-bold text-sky-700 ring-1 ring-sky-100">Hitung ulang porsi</button>@endif
                </div>
                @if ($planningSummary)
                <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl bg-slate-50 p-3">
                        <p class="text-[10px] font-semibold text-slate-500">Penerima aktif</p>
                        <p class="mt-1 text-xl font-bold text-slate-950">{{ number_format($planningSummary['active_members'], 0, ',', '.') }}</p>
                    </div>
                    <div class="rounded-xl bg-sky-50 p-3">
                        <p class="text-[10px] font-semibold text-sky-700">Porsi kecil + buffer</p>
                        <p class="mt-1 text-xl font-bold text-slate-950">{{ number_format($planningSummary['buffered_small_portions'], 0, ',', '.') }}</p>
                    </div>
                    <div class="rounded-xl bg-violet-50 p-3">
                        <p class="text-[10px] font-semibold text-violet-700">Porsi besar + buffer</p>
                        <p class="mt-1 text-xl font-bold text-slate-950">{{ number_format($planningSummary['buffered_large_portions'], 0, ',', '.') }}</p>
                    </div>
                    <div class="rounded-xl bg-emerald-50 p-3">
                        <p class="text-[10px] font-semibold text-emerald-700">Total rencana</p>
                        <p class="mt-1 text-xl font-bold text-slate-950">{{ number_format($planningSummary['buffered_total_portions'], 0, ',', '.') }}</p>
                    </div>
                </div>
                @else
                <div class="mt-5 rounded-xl border border-dashed border-slate-200 p-5 text-center text-xs text-slate-500">Aktifkan atau setujui periode penerima terlebih dahulu agar porsi dapat dihitung.</div>
                @endif
            </section>

            @if (auth()->user()->is_super_admin || auth()->user()->can('menus.create'))
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Siklus baru</p>
                <h3 class="mt-1 text-base font-bold text-slate-950">Bentuk hari pelayanan</h3>
                <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-1"><label><span class="mb-1.5 block text-xs font-semibold text-slate-600">Nama siklus</span><input wire:model="newCycleName" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Contoh: Siklus Menu Minggu 1">@error('newCycleName')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <div class="grid grid-cols-2 gap-3"><label><span class="mb-1.5 block text-xs font-semibold text-slate-600">Mulai</span><input wire:model="newStartDate" type="date" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">@error('newStartDate')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label><label><span class="mb-1.5 block text-xs font-semibold text-slate-600">Jumlah hari</span><input wire:model="newCycleLength" type="number" min="1" max="60" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">@error('newCycleLength')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label></div><button wire:click="createCycle" class="h-11 rounded-xl bg-[#081d3a] px-4 text-xs font-bold text-white">Buat siklus dari periode</button>
                </div>
            </section>
            @endif
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:p-5">
            <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
                <label class="block flex-1"><span class="mb-2 block text-xs font-bold text-slate-600">Siklus yang dikerjakan</span><select wire:model.live="selectedCycleId" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-700">
                        <option value="">Belum ada siklus</option>@foreach ($cycles as $cycleOption)<option value="{{ $cycleOption->id }}">{{ $cycleOption->code }} — {{ $cycleOption->name }} ({{ $cycleOption->cycle_length_days }} hari)</option>@endforeach
                    </select></label>
                @if ($cycle)
                    <div class="flex flex-wrap gap-2">
                        <span class="inline-flex h-11 items-center rounded-xl bg-slate-100 px-3 text-xs font-bold text-slate-600">{{ $cycle->status->label() }}</span>
                        @if (auth()->user()->is_super_admin || auth()->user()->can('menus.export'))
                            <a href="{{ route('nutrition.menu-cycles.pdf', $cycle) }}" target="_blank" class="inline-flex h-11 items-center rounded-xl border border-slate-200 px-3 text-xs font-bold text-slate-600">PDF</a>
                        @endif
                        @if ($cycle->isEditable() && (auth()->user()->is_super_admin || auth()->user()->can('menus.update')))
                            <button wire:click="saveAll" class="h-11 rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white">Simpan semua</button>
                        @endif
                        @if ($cycle->status === \App\Enums\NutritionRecordStatus::Approved && (auth()->user()->is_super_admin || auth()->user()->can('menus.activate')))
                            <button wire:click="activateCycle" wire:confirm="Aktifkan siklus ini? Siklus aktif lama akan diarsipkan." class="h-11 rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white">Aktifkan & terapkan</button>
                        @endif
                    </div>
                @endif
            </div>
        </section>

        @if ($cycle)
        @if ($cycle->revision_notes)<div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700"><strong>Catatan revisi:</strong> {{ $cycle->revision_notes }}</div>@endif

        @if ($cycle->isEditable() && (auth()->user()->is_super_admin || auth()->user()->can('menus.update')))
        <details class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <summary class="cursor-pointer text-sm font-bold text-slate-800">Tempel beberapa baris dari Excel</summary>
            <div class="mt-4 grid gap-3 lg:grid-cols-[130px_minmax(0,1fr)_180px]"><label><span class="mb-1.5 block text-xs font-semibold text-slate-600">Mulai hari ke-</span><input wire:model="pasteStartDayNumber" type="number" min="1" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></label><label><span class="mb-1.5 block text-xs font-semibold text-slate-600">Urutan kolom: Nama, Karbohidrat, Hewani, Nabati, Susu, Sayur, Buah, Catatan</span><textarea wire:model="pasteBuffer" rows="4" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="Tempel sel Excel di sini"></textarea>@error('pasteBuffer')<span class="text-xs text-rose-600">{{ $message }}</span>@enderror</label><button wire:click="pasteExcel" class="self-end rounded-xl bg-sky-50 px-4 py-3 text-xs font-bold text-sky-700 ring-1 ring-sky-100">Masukkan ke matriks</button></div>
        </details>
        @endif

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-100 p-5">
                <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-end">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Matriks harian</p>
                        <h3 class="mt-1 text-lg font-bold text-slate-950">{{ $cycle->name }}</h3>
                    </div>
                    <p class="text-xs text-slate-500">{{ $cycle->start_date?->translatedFormat('d M') }}–{{ $cycle->end_date?->translatedFormat('d M Y') }} · {{ number_format($cycle->bufferedTotalPortions(), 0, ',', '.') }} porsi/hari</p>
                </div>
            </div>
            <div class="space-y-4 p-4 sm:p-5">
                @foreach ($rows as $dayId => $row)
                <article wire:key="matrix-day-{{ $dayId }}" class="rounded-2xl border border-slate-200 bg-slate-50/50 p-4">
                    <div class="flex flex-col justify-between gap-3 border-b border-slate-100 pb-4 sm:flex-row sm:items-center">
                        <div class="flex items-center gap-3"><span class="grid size-10 place-items-center rounded-xl bg-[#081d3a] text-sm font-bold text-white">{{ $row['day_number'] }}</span>
                            <div>
                                <h4 class="text-sm font-bold text-slate-800">Hari ke-{{ $row['day_number'] }}</h4>
                                <p class="mt-0.5 text-xs text-slate-500">{{ $row['service_date'] }}</p>
                            </div>
                        </div><span class="self-start rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-600">{{ $row['status_label'] }}</span>
                    </div>

                    @if ($row['is_holiday'])
                    <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-5 text-center">
                        <p class="text-xs font-black uppercase tracking-[.16em] text-amber-700">Libur Pelayanan</p>
                        <p class="mt-1 text-sm font-bold text-slate-900">{{ $row['holiday_name'] }}</p>
                        <p class="mt-1 text-xs text-slate-600">Tidak diperlukan menu pada tanggal ini.</p>
                        @if ($row['has_holiday_conflict'])
                        <p class="mt-3 text-xs font-semibold text-rose-700">Menu lama masih terhubung. Siklus yang telah dikunci wajib melalui proses revisi.</p>
                        <div class="mt-3 flex flex-wrap justify-center gap-2">
                            @if(!$row['holiday_revision_request_id'] && (auth()->user()->is_super_admin || auth()->user()->can('menus.submit')))
                            <button wire:click="requestHolidayRevision({{ $dayId }})" class="rounded-lg bg-amber-600 px-3 py-2 text-[10px] font-bold text-white">Ajukan pelepasan menu</button>
                            @elseif($row['holiday_revision_status'] === 'pending_authorization')
                            <span class="rounded-lg bg-white px-3 py-2 text-[10px] font-bold text-amber-700 ring-1 ring-amber-200">Menunggu persetujuan revisi</span>
                            @if(auth()->user()->is_super_admin || auth()->user()->can('menus.approve'))
                            <button wire:click="authorizeHolidayRevision({{ $row['holiday_revision_request_id'] }})" wire:confirm="Setujui pelepasan menu dari tanggal libur ini?" class="rounded-lg bg-emerald-600 px-3 py-2 text-[10px] font-bold text-white">Setujui pelepasan</button>
                            @endif
                            @endif
                        </div>
                        @endif
                    </div>
                    @else

                    @if ($row['can_assign'])<div class="mt-4 flex flex-col gap-2 sm:flex-row"><select wire:model="rows.{{ $dayId }}.selected_menu_id" class="h-10 flex-1 rounded-xl border border-slate-200 bg-white px-3 text-xs">
                            <option value="">Pilih menu draft yang sudah ada</option>@foreach ($menuOptions as $option)<option value="{{ $option->id }}">{{ $option->code }} — {{ $option->name }}</option>@endforeach
                        </select><button wire:click="assignExisting({{ $dayId }})" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-600">Gunakan</button></div>@endif

                    <div class="mt-4 grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                        @foreach ([['menu_name','Nama menu','Contoh: Ayam semur lengkap'],['staple','Karbohidrat','Nasi putih'],['animal_protein','Protein hewani','Ayam semur'],['plant_protein','Protein nabati','Tempe'],['milk','Susu','Opsional bila ada nabati'],['fruit','Buah','Pisang']] as [$field,$label,$placeholder])
                        <label class="block"><span class="mb-1.5 block text-xs font-semibold text-slate-600">{{ $label }}</span><input wire:model="rows.{{ $dayId }}.{{ $field }}" @disabled(! $row['can_edit']) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-xs disabled:opacity-60" placeholder="{{ $placeholder }}"></label>
                        @endforeach
                        <label class="block md:col-span-2"><span class="mb-1.5 block text-xs font-semibold text-slate-600">Sayur (satu per baris)</span><textarea wire:model="rows.{{ $dayId }}.vegetables" @disabled(! $row['can_edit']) rows="2" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs disabled:opacity-60"></textarea></label><label class="block md:col-span-2"><span class="mb-1.5 block text-xs font-semibold text-slate-600">Catatan</span><textarea wire:model="rows.{{ $dayId }}.notes" @disabled(! $row['can_edit']) rows="2" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs disabled:opacity-60"></textarea></label>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-2">@if ($row['can_edit'])
                        <button wire:click="saveRow({{ $dayId }})" class="rounded-lg bg-[#081d3a] px-3 py-2 text-[10px] font-bold text-white">
                            Simpan hari ini
                        </button>@endif @if ($row['menu_id'])@if ($row['can_assign'])
                        <button wire:click="copyToNextDay({{ $dayId }})" class="rounded-lg bg-sky-50 px-3 py-2 text-[10px] font-bold text-sky-700 ring-1 ring-sky-100">
                            Pakai hari berikutnya
                        </button>
                        <button wire:click="duplicateMenu({{ $dayId }})" class="rounded-lg bg-violet-50 px-3 py-2 text-[10px] font-bold text-violet-700 ring-1 ring-violet-100">
                            Salinan terpisah
                        </button>
                        <button wire:click="detachMenu({{ $dayId }})" wire:confirm="Lepas menu dari hari ini?" class="rounded-lg px-3 py-2 text-[10px] font-bold text-rose-700 hover:bg-rose-50">
                            Lepas
                        </button>@endif @if (isset($menuRecipeUrls[$row['menu_id']]))
                        <a
                            wire:navigate
                            href="{{ $menuRecipeUrls[$row['menu_id']] }}"
                            class="rounded-lg border border-slate-200 bg-white px-3 py-2 text-[10px] font-bold text-slate-600">
                            Lihat resep
                            <x-v3.icon name="clipboard" class="ml-1 inline size-3" />
                        </a>
                        @endif
                        @if (isset($menuNutritionUrls[$row['menu_id']]))
                        <a
                            wire:navigate
                            href="{{ $menuNutritionUrls[$row['menu_id']] }}"
                            class="rounded-lg bg-cyan-50 px-3 py-2 text-[10px] font-bold text-cyan-700 ring-1 ring-cyan-100">
                            Lihat nilai gizi
                            <x-v3.icon name="calculator" class="ml-1 inline size-3" />
                        </a>
                        @endif
                        @endif
                    </div>

                    @if ($row['menu_id'])
                    <section class="mt-4 rounded-2xl border border-cyan-200 bg-cyan-50/60 p-4">
                        <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-xs font-black uppercase tracking-[.14em] text-cyan-800">Menu 3B</p>
                                    <span class="rounded-full px-2.5 py-1 text-[9px] font-black tracking-wide {{ $row['variant_3b_menu_id'] ? 'bg-violet-100 text-violet-700' : 'bg-emerald-100 text-emerald-700' }}">
                                        {{ $row['variant_3b_state'] }}
                                    </span>
                                </div>
                                <p class="mt-1 text-xs font-semibold text-slate-700">Bumil, Busui, dan Balita</p>
                                <p class="mt-1 text-[11px] leading-5 text-slate-500">
                                    @if ($row['variant_3b_menu_id'])
                                        {{ $row['variant_3b_menu_name'] }}
                                    @else
                                        Mengikuti Menu Utama. Buat menu berbeda hanya jika diperlukan pada hari ini.
                                    @endif
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @if (! $row['variant_3b_menu_id'] && $row['can_assign'])
                                <button wire:click="createThreeBVariant({{ $dayId }})" class="rounded-lg bg-cyan-700 px-3 py-2 text-[10px] font-bold text-white">
                                    + Buat Menu 3B Berbeda
                                </button>
                                @elseif ($row['variant_3b_menu_id'])
                                    @if (isset($menuRecipeUrls[$row['variant_3b_menu_id']]))
                                    <a wire:navigate href="{{ $menuRecipeUrls[$row['variant_3b_menu_id']] }}" class="rounded-lg bg-white px-3 py-2 text-[10px] font-bold text-cyan-800 ring-1 ring-cyan-200">
                                        {{ $row['can_assign'] ? 'Edit' : 'Lihat' }} Menu 3B
                                    </a>
                                    @endif
                                    @if (isset($menuNutritionUrls[$row['variant_3b_menu_id']]))
                                    <a wire:navigate href="{{ $menuNutritionUrls[$row['variant_3b_menu_id']] }}" class="rounded-lg bg-white px-3 py-2 text-[10px] font-bold text-slate-700 ring-1 ring-slate-200">
                                        Nilai gizi 3B
                                    </a>
                                    @endif
                                    @if ($row['can_assign'])
                                    <button wire:click="removeThreeBVariant({{ $dayId }})" wire:confirm="Menu 3B akan kembali mengikuti Menu Utama." class="rounded-lg px-3 py-2 text-[10px] font-bold text-rose-700 hover:bg-rose-50">
                                        Hapus Perbedaan
                                    </button>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </section>
                    @endif
                    @endif
                </article>
                @endforeach
            </div>
        </section>

        @if (! in_array($cycle->status, [
            \App\Enums\NutritionRecordStatus::Approved,
            \App\Enums\NutritionRecordStatus::Active,
            \App\Enums\NutritionRecordStatus::Archived,
        ], true))
        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_420px]">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Kesiapan</p>
                <h3 class="mt-1 text-base font-bold text-slate-950">Validasi sebelum pengajuan</h3>
                <div class="mt-4 space-y-2">@forelse ($readiness['blocking'] as $issue)<p class="rounded-xl bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ $issue }}</p>@empty<p class="rounded-xl bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-700">Tidak ada penghambat utama.</p>@endforelse @foreach ($readiness['warnings'] as $warning)<p class="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-700">{{ $warning }}</p>@endforeach</div>
            </section>
            <aside class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Workflow</p>
                <h3 class="mt-1 text-base font-bold text-slate-950">Status siklus</h3><label class="mt-4 block"><span class="mb-1.5 block text-xs font-semibold text-slate-600">Catatan keputusan</span><textarea wire:model="cycleDecisionNotes" rows="3" class="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm" placeholder="Wajib saat meminta revisi"></textarea></label>
                <div class="mt-3 grid gap-2">@if ($cycle->isEditable() && (auth()->user()->is_super_admin || auth()->user()->can('menus.submit')))<button wire:click="submitCycle" wire:confirm="Validasi dan ajukan siklus ini?" class="h-10 rounded-xl bg-[#081d3a] text-xs font-bold text-white">Validasi & ajukan</button>@endif @if ($cycle->status === \App\Enums\NutritionRecordStatus::Submitted && (auth()->user()->is_super_admin || auth()->user()->can('menus.approve')))<button wire:click="approveCycle" wire:confirm="Setujui dan kunci siklus ini?" class="h-10 rounded-xl bg-emerald-600 text-xs font-bold text-white">Setujui siklus</button><button wire:click="returnCycle" class="h-10 rounded-xl bg-rose-50 text-xs font-bold text-rose-700 ring-1 ring-rose-100">Kembalikan untuk revisi</button>@endif</div>
            </aside>
        </div>
        @endif
        @else
        <section class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-12 text-center"><x-v3.icon name="calendar" class="mx-auto size-7 text-slate-400" />
            <p class="mt-4 text-sm font-bold text-slate-700">Belum ada siklus menu</p>
            <p class="mt-1 text-xs text-slate-500">Pilih periode penerima dan buat siklus pertama.</p>
        </section>
        @endif
    </div>
</x-v3.shell>
