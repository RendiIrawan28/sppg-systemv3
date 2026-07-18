<x-v3.shell :$unit :$navigation :$roleLabel :title="$periodId ? 'Ubah Periode Penerima' : 'Buat Periode Penerima'" eyebrow="Snapshot 14 hari">
    <div class="mx-auto max-w-4xl space-y-5">
        <div>
            <a wire:navigate href="{{ route('v3.beneficiary-periods.index') }}" class="inline-flex items-center gap-2 text-xs font-bold text-sky-700 hover:text-sky-900"><x-v3.icon name="arrow-left" class="size-4" /> Kembali ke periode</a>
            <h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950">{{ $periodId ? 'Perbarui identitas periode' : 'Buat snapshot 14 hari baru' }}</h2>
            <p class="mt-1 text-sm text-slate-500">Tanggal selesai dihitung otomatis 13 hari setelah tanggal mulai.</p>
        </div>

        <form wire:submit="save" class="space-y-5">
            @error('form') <div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div> @enderror
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-start gap-3 border-b border-slate-100 pb-5"><span class="grid size-11 shrink-0 place-items-center rounded-xl bg-sky-50 text-sky-700 ring-1 ring-sky-100"><x-v3.icon name="calendar" class="size-5" /></span><div><p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Identitas periode</p><h3 class="mt-1 text-lg font-bold text-slate-950">Rentang dan sumber snapshot</h3></div></div>
                @php($inputClass = 'h-12 w-full rounded-xl border border-slate-200 bg-white px-4 text-sm text-slate-900 outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100')
                <div class="mt-5 grid gap-5 sm:grid-cols-2">
                    <label><span class="mb-2 block text-sm font-semibold text-slate-700">Kode periode <b class="text-rose-600">*</b></span><input wire:model="code" class="{{ $inputClass }}">@error('code')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label><span class="mb-2 block text-sm font-semibold text-slate-700">Nama periode <b class="text-rose-600">*</b></span><input wire:model="name" class="{{ $inputClass }}">@error('name')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label><span class="mb-2 block text-sm font-semibold text-slate-700">Tanggal mulai <b class="text-rose-600">*</b></span><input wire:model.live="startDate" type="date" class="{{ $inputClass }}">@error('startDate')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    <label><span class="mb-2 block text-sm font-semibold text-slate-700">Tanggal selesai</span><input wire:model="endDate" type="date" readonly class="{{ $inputClass }} bg-slate-50">@error('endDate')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    @if (! $periodId)
                        <label class="sm:col-span-2"><span class="mb-2 block text-sm font-semibold text-slate-700">Salin data dari periode sebelumnya</span><select wire:model="sourcePeriodId" class="{{ $inputClass }}"><option value="">Mulai tanpa snapshot</option>@foreach ($sourcePeriods as $source)<option value="{{ $source->id }}">{{ $source->name }} · {{ $source->start_date->translatedFormat('d M') }}–{{ $source->end_date->translatedFormat('d M Y') }}</option>@endforeach</select><span class="mt-1.5 block text-xs text-slate-500">Opsional. Instansi dan penerima disalin sebagai snapshot baru yang dapat diedit.</span>@error('sourcePeriodId')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                    @endif
                    <label class="sm:col-span-2"><span class="mb-2 block text-sm font-semibold text-slate-700">Catatan periode</span><textarea wire:model="notes" rows="4" class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-900 outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100" placeholder="Catatan perubahan atau konteks periode"></textarea>@error('notes')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror</label>
                </div>
            </section>

            <div class="rounded-2xl border border-sky-100 bg-sky-50 p-4 text-xs leading-5 text-sky-800"><strong>Langkah berikutnya:</strong> setelah periode dibuat, ambil master penerima aktif atau gunakan hasil salinan, periksa ringkasan, lalu ajukan ke Kepala SPPG.</div>

            <div class="flex flex-col-reverse justify-between gap-3 sm:flex-row sm:items-center">
                @if ($periodId && (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.delete')))
                    <button type="button" wire:click="delete" wire:confirm="Hapus periode draft ini?" class="inline-flex h-12 items-center justify-center gap-2 rounded-xl px-4 text-sm font-bold text-rose-700 hover:bg-rose-50"><x-v3.icon name="trash" class="size-4" /> Hapus draft</button>
                @else<span></span>@endif
                <div class="flex gap-3"><a wire:navigate href="{{ route('v3.beneficiary-periods.index') }}" class="inline-flex h-12 items-center rounded-xl border border-slate-200 px-5 text-sm font-bold text-slate-600 hover:bg-slate-50">Batal</a><button type="submit" wire:loading.attr="disabled" class="inline-flex h-12 min-w-40 items-center justify-center rounded-xl bg-[#081d3a] px-5 text-sm font-bold text-white shadow-lg disabled:opacity-60"><span wire:loading.remove wire:target="save">Simpan periode</span><span wire:loading wire:target="save">Menyimpan...</span></button></div>
            </div>
        </form>
    </div>
</x-v3.shell>
