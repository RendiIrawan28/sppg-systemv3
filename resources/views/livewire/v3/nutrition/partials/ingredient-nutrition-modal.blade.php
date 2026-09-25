@if ($detail)
    <div x-data x-on:keydown.escape.window="$wire.{{ $closeAction }}()" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/70 p-3 backdrop-blur-sm sm:p-6" role="dialog" aria-modal="true" aria-label="Nilai gizi {{ $detail['name'] }}">
        <button type="button" wire:click="{{ $closeAction }}" class="absolute inset-0 cursor-default" aria-label="Tutup nilai gizi"></button>
        <div class="relative flex max-h-[94vh] w-full max-w-6xl flex-col overflow-hidden rounded-2xl bg-white text-slate-900 shadow-2xl dark:bg-slate-900 dark:text-slate-100">
            <div class="flex items-start justify-between gap-4 border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <div><p class="text-xs font-bold uppercase tracking-widest text-sky-700 dark:text-sky-300">Nilai gizi bahan</p><h3 class="mt-1 text-xl font-bold">{{ $detail['name'] }}</h3><p class="text-xs text-slate-500 dark:text-slate-400">{{ $detail['code'] }} · {{ $detail['unit'] }}</p></div>
                <button type="button" wire:click="{{ $closeAction }}" class="grid size-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-xl dark:bg-slate-800" aria-label="Tutup">×</button>
            </div>
            <div class="min-h-0 space-y-5 overflow-y-auto p-5">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-xl bg-sky-50 p-4 dark:bg-sky-500/10"><p class="text-xs text-slate-500 dark:text-slate-400">Basis referensi</p><strong class="mt-1 block text-sm">{{ $detail['basis'] }}</strong></div>
                    <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800"><p class="text-xs text-slate-500 dark:text-slate-400">Sumber</p><strong class="mt-1 block break-words text-sm">{{ $detail['source'] }}</strong></div>
                    <div class="rounded-xl bg-slate-50 p-4 dark:bg-slate-800"><p class="text-xs text-slate-500 dark:text-slate-400">Konversi stok (informasi)</p><strong class="mt-1 block text-sm">{{ $detail['grams_per_unit'] !== null ? '1 '.$detail['unit'].' = '.number_format((float) $detail['grams_per_unit'], 2, ',', '.').' g' : 'Belum tersedia' }}</strong></div>
                </div>
                <div class="flex flex-wrap gap-2">@foreach($detail['status'] as $flag)<span class="rounded-full px-3 py-1 text-xs font-bold {{ $flag === 'Lengkap' ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-200' : 'bg-amber-100 text-amber-900 dark:bg-amber-500/15 dark:text-amber-200' }}">{{ $flag }}</span>@endforeach</div>
                @if($detail['basis_fallback'])<p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">Basis master belum valid. Kalkulator yang aktif saat ini memakai angka 100 sebagai fallback; kontribusi tetap ditampilkan sesuai hasil kalkulator, tanpa memperbaiki data otomatis.</p>@endif
                @if(! $detail['has_nutrition'])<p class="rounded-xl border border-dashed border-slate-300 p-4 text-sm text-slate-600 dark:border-slate-700 dark:text-slate-300">Data nilai gizi bahan belum tersedia.</p>@endif
                @if($detail['conversion_warning'])
                    <p class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">Periksa basis: bahan dihitung per 1 satuan, sedangkan satuan atau faktor konversi resep dapat menghasilkan kuantitas efektif berbeda. Kontribusi di bawah tetap mengikuti kalkulator sistem saat ini; data ini tidak diubah otomatis.</p>
                @endif
                @if(count($detail['profiles']))
                    <section><h4 class="font-bold">Kontribusi sesuai gramasi saat ini</h4><div class="mt-2 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">@foreach($detail['profiles'] as $profile)<div class="rounded-xl border border-slate-200 p-3 text-sm dark:border-slate-700"><span class="block text-xs text-slate-500 dark:text-slate-400">{{ $profile['label'] }}</span><strong class="block">{{ $profile['quantity'] === null ? 'Belum diisi' : number_format($profile['quantity'], 4, ',', '.').' g/satuan efektif' }}</strong>@if($profile['fallback'])<span class="mt-1 block text-xs text-amber-700 dark:text-amber-300">Mengikuti {{ $profile['fallback'] }}</span>@endif</div>@endforeach</div></section>
                @endif
                <div class="max-w-full overflow-x-auto rounded-xl border border-slate-200 dark:border-slate-700">
                    <table class="min-w-full text-left text-sm">
                        <thead class="bg-slate-50 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300"><tr><th class="px-4 py-3">Komponen</th><th class="px-4 py-3">Nilai referensi</th><th class="px-4 py-3">Sumber komponen</th>@foreach($detail['profiles'] as $profile)<th class="whitespace-nowrap px-4 py-3">{{ $profile['label'] }}</th>@endforeach</tr></thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            @forelse($detail['components'] as $component)
                                <tr><th class="whitespace-nowrap px-4 py-3 font-semibold">{{ $component['name'] }}</th><td class="whitespace-nowrap px-4 py-3">{{ $component['reference'] === null ? '–' : number_format($component['reference'], 4, ',', '.').' '.$component['unit'].' / '.$detail['basis'] }}</td><td class="px-4 py-3">{{ $component['source'] ?: '–' }}</td>@foreach($detail['profiles'] as $key => $profile)<td class="whitespace-nowrap px-4 py-3">{{ $component['contributions'][$key] === null ? '–' : number_format($component['contributions'][$key], 4, ',', '.').' '.$component['unit'] }}</td>@endforeach</tr>
                            @empty
                                <tr><td colspan="{{ 3 + count($detail['profiles']) }}" class="px-4 py-8 text-center text-slate-500 dark:text-slate-400">Data nilai gizi bahan belum tersedia.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-slate-500 dark:text-slate-400">Angka kontribusi mengikuti rumus dan fallback profil yang dipakai kalkulator menu. Pratinjau gramasi belum menyimpan perubahan resep.</p>
            </div>
        </div>
    </div>
@endif
