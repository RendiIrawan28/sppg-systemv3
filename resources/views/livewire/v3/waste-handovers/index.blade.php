<x-v3.shell :$unit :$navigation :$roleLabel title="Berita Acara Limbah" eyebrow="Persiapan · Pencucian · Kebersihan">
    <div class="mx-auto max-w-[1450px] space-y-5 text-slate-900 dark:text-slate-100">
        <x-v3.flash-alert />
        <x-v3.date-filter label="Tanggal berita acara" />
        <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
            <div><p class="text-xs font-bold uppercase tracking-[.18em] text-sky-600">Dokumen bersama tiga divisi</p><h2 class="mt-2 text-2xl font-bold">Berita Acara Serah Terima Limbah</h2><p class="mt-1 max-w-3xl text-sm text-slate-500 dark:text-slate-400">Satu form dan satu format ekspor untuk limbah Persiapan, Pencucian, serta Kebersihan.</p></div>
            @if($canCreate)<a wire:navigate href="{{ route('v3.waste-handovers.create') }}" class="inline-flex h-11 items-center justify-center rounded-xl bg-sky-600 px-5 text-sm font-bold text-white">+ Buat berita acara</a>@endif
        </div>
        <section class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm md:grid-cols-[1fr_240px] dark:border-slate-700 dark:bg-slate-900">
            <input wire:model.live.debounce.300ms="search" type="search" placeholder="Cari nomor atau nama pihak..." class="h-11 rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm dark:border-slate-700 dark:bg-slate-950">
            <select wire:model.live="division" class="h-11 rounded-xl border border-slate-200 bg-white px-3 text-sm dark:border-slate-700 dark:bg-slate-950"><option value="">Semua divisi</option>@foreach($divisionOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
        </section>
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <div class="overflow-x-auto"><table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-slate-700"><thead class="bg-slate-50 dark:bg-slate-800"><tr><th class="px-5 py-3 text-left">Dokumen</th><th class="px-5 py-3 text-left">Tanggal</th><th class="px-5 py-3 text-left">Divisi</th><th class="px-5 py-3 text-left">Para pihak</th><th class="px-5 py-3 text-left">Status</th><th class="px-5 py-3"></th></tr></thead><tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse($records as $record)<tr class="hover:bg-slate-50 dark:hover:bg-slate-800/60"><td class="px-5 py-4"><p class="font-bold">{{ $record->report_number }}</p><p class="text-xs text-slate-500">{{ $record->items->count() }} jenis limbah</p></td><td class="px-5 py-4">{{ $record->report_date?->format('d/m/Y') }}</td><td class="px-5 py-4">{{ $record->division_type->label() }}</td><td class="px-5 py-4"><p>{{ $record->first_party_name }}</p><p class="text-xs text-slate-500">kepada {{ $record->second_party_name }}</p></td><td class="px-5 py-4"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold dark:bg-slate-700">{{ $record->status->label() }}</span></td><td class="px-5 py-4 text-right"><a wire:navigate href="{{ route('v3.waste-handovers.show', $record) }}" class="text-xs font-bold text-sky-600">Buka</a></td></tr>
                @empty<tr><td colspan="6" class="px-5 py-12 text-center text-slate-500">Belum ada berita acara limbah.</td></tr>@endforelse
            </tbody></table></div><div class="border-t border-slate-200 p-4 dark:border-slate-700">{{ $records->links() }}</div>
        </section>
    </div>
</x-v3.shell>
