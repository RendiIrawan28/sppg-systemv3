<x-v3.shell :$unit :$navigation :$roleLabel :title="$definition['label']" eyebrow="Operasional harian">
    <div class="mx-auto max-w-[1450px] space-y-5">
        @if (session('v3.status'))<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('v3.status') }}</div>@endif
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div><p class="text-xs font-bold uppercase tracking-[.18em] text-sky-700">Ruang kerja divisi</p><h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">{{ $definition['label'] }}</h2><p class="mt-1 max-w-2xl text-sm text-slate-500">{{ $definition['description'] }}</p></div>
            @if ($canCreate && $module !== 'distribusi')<a wire:navigate href="{{ route('v3.operations.create', ['module' => $module]) }}" class="inline-flex h-11 items-center justify-center gap-2 rounded-xl bg-sky-600 px-5 text-sm font-bold text-white shadow-lg shadow-sky-600/20"><x-v3.icon name="plus" class="size-4" /> Tambah {{ $definition['label'] }}</a>@endif
        </div>

        <section class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><label class="relative block max-w-lg"><x-v3.icon name="search" class="pointer-events-none absolute left-3 top-3.5 size-4 text-slate-400" /><input wire:model.live.debounce.300ms="search" type="search" placeholder="Cari nomor dokumen..." class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm text-slate-800 outline-none focus:border-sky-400 focus:ring-2 focus:ring-sky-100"></label></section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-100 text-sm"><thead class="bg-slate-50"><tr><th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500">Dokumen</th><th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500">Tanggal</th><th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500">Tahap</th><th class="px-5 py-3 text-left text-[10px] font-bold uppercase tracking-[.14em] text-slate-500">Laporan</th><th class="px-5 py-3"></th></tr></thead><tbody class="divide-y divide-slate-100">
                @forelse ($records as $record)
                    @php($state = $record->state instanceof BackedEnum ? $record->state : null)
                    @php($status = $record->status instanceof BackedEnum ? $record->status : null)
                    <tr class="hover:bg-slate-50/70"><td class="px-5 py-4"><p class="font-bold text-slate-900">{{ $record->{$definition['number']} }}</p><p class="mt-1 text-xs text-slate-400">{{ $record->menu_name_snapshot ?? $record->cleaningArea?->name ?? '—' }}</p></td><td class="px-5 py-4 text-slate-600">{{ $record->{$definition['date']}?->translatedFormat('d M Y') ?? '—' }}</td><td class="px-5 py-4"><span class="rounded-full bg-sky-50 px-2.5 py-1 text-[10px] font-bold text-sky-700">{{ $state && method_exists($state, 'label') ? $state->label() : str($record->state)->headline() }}</span></td><td class="px-5 py-4"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-600">{{ $module === 'distribusi' ? ($record->state === App\Enums\DistributionRunState::Returned ? 'Selesai' : 'Berjalan') : ($status && method_exists($status, 'label') ? $status->label() : str($record->status)->headline()) }}</span></td><td class="px-5 py-4 text-right"><a wire:navigate href="{{ route('v3.operations.show', ['module' => $module, 'record' => $record]) }}" class="text-xs font-bold text-sky-700">Buka rincian</a></td></tr>
                @empty
                    <tr><td colspan="5" class="px-5 py-14 text-center"><p class="font-semibold text-slate-600">Belum ada data {{ strtolower($definition['label']) }}.</p><p class="mt-1 text-xs text-slate-400">Dokumen dari rencana lapangan aktif juga akan muncul otomatis di sini.</p></td></tr>
                @endforelse
            </tbody></table></div>
            @if ($records->hasPages())<div class="border-t border-slate-100 p-4">{{ $records->links() }}</div>@endif
        </section>
    </div>
</x-v3.shell>
