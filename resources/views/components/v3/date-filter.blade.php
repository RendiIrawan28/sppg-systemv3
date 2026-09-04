@props([
    'label' => 'Tanggal data',
    'description' => 'Daftar utama hanya menampilkan data pada tanggal yang dipilih.',
])

<section {{ $attributes->class(['rounded-2xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-700 dark:bg-slate-900']) }}>
    <div class="flex flex-col justify-between gap-3 md:flex-row md:items-center">
        <div>
            <p class="text-xs font-bold uppercase tracking-[.14em] text-sky-700 dark:text-sky-400">{{ $label }}</p>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ $description }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button wire:click="previousWorkDate" type="button" title="Tanggal sebelumnya" class="grid size-11 place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:border-sky-300 hover:text-sky-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300">‹</button>
            <input wire:model="workDate" wire:change="selectWorkDate($event.target.value)" type="date" aria-label="{{ $label }}" class="h-11 min-w-44 rounded-xl border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-100 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-100">
            <button wire:click="nextWorkDate" type="button" title="Tanggal berikutnya" class="grid size-11 place-items-center rounded-xl border border-slate-200 bg-white text-slate-600 hover:border-sky-300 hover:text-sky-700 dark:border-slate-700 dark:bg-slate-950 dark:text-slate-300">›</button>
            <button wire:click="useTodayWorkDate" type="button" class="h-11 rounded-xl bg-[#081d3a] px-4 text-xs font-bold text-white hover:bg-sky-800 dark:bg-sky-600">Hari ini</button>
        </div>
    </div>
    <p wire:loading wire:target="selectWorkDate,previousWorkDate,nextWorkDate,useTodayWorkDate" role="status" class="mt-2 text-xs text-sky-700 dark:text-sky-300">Memuat pekerjaan pada tanggal yang dipilih...</p>
</section>
