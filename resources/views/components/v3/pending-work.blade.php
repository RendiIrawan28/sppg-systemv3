@props(['records', 'module', 'definition'])

@if ($records->isNotEmpty())
    <section class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm dark:border-amber-400/30 dark:bg-amber-500/10">
        <div class="flex items-start gap-3">
            <div class="grid size-10 shrink-0 place-items-center rounded-xl bg-amber-100 font-bold text-amber-700 dark:bg-amber-400/15 dark:text-amber-300">!</div>
            <div class="min-w-0 flex-1">
                <h3 class="font-bold text-amber-950 dark:text-amber-100">Pekerjaan tanggal lain yang belum selesai</h3>
                <p class="mt-1 text-xs text-amber-800/80 dark:text-amber-200/80">Tetap ditampilkan agar pekerjaan tertunda tidak terlewat.</p>
                <div class="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($records as $record)
                        <a wire:navigate href="{{ route('v3.operations.show', ['module' => $module, 'record' => $record]) }}" class="rounded-xl border border-amber-200 bg-white p-3 transition hover:border-amber-400 dark:border-amber-400/20 dark:bg-slate-900">
                            <p class="truncate text-xs font-bold text-slate-800 dark:text-slate-100">{{ $record->{$definition['number']} }}</p>
                            <p class="mt-1 text-[11px] text-slate-500 dark:text-slate-400">{{ $record->{$definition['date']}?->translatedFormat('d M Y') ?? '—' }} · {{ $record->state?->label() ?? str($record->state)->headline() }}</p>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    </section>
@endif
