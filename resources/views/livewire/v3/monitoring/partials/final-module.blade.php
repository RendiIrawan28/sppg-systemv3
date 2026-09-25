@php
    $definition = \App\Support\V3\MonitoringNavigation::FINAL_MODULES[$activeTab];
    $sourceRoute = match ($activeTab) {
        'field-assistant' => 'v3.field.plans.index',
        'attendance' => 'v3.attendance.index',
        default => 'v3.operations.index',
    };
@endphp
<div class="flex flex-wrap items-center justify-between gap-3">
    <div><h2 class="text-xl font-bold">{{ $definition['label'] }}</h2><p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ \Carbon\Carbon::parse($workDate)->translatedFormat('d F Y') }} · Semua rincian dapat dibaca di halaman ini.</p></div>
    @if (\Illuminate\Support\Facades\Route::has($sourceRoute) && (auth()->user()->is_super_admin || auth()->user()->can($definition['permission'])))
        <a wire:navigate href="{{ route($sourceRoute, array_filter(['module' => $definition['module'], 'tanggal' => $workDate])) }}" class="rounded-xl bg-sky-50 px-4 py-3 text-xs font-bold text-sky-700 dark:bg-sky-500/10 dark:text-sky-300">Buka modul sumber ↗</a>
    @endif
</div>
<section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
    @foreach ($finalModule['cards'] as $card)
        <article class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
            <p class="text-xs text-slate-500 dark:text-slate-400">{{ $card['label'] }}</p>
            <p class="mt-2 text-2xl font-bold">{{ is_numeric($card['value']) ? number_format($card['value'], 0, ',', '.') : $card['value'] }}</p>
            <p class="mt-2 text-xs leading-5 text-slate-500 dark:text-slate-400">{{ $card['detail'] }}</p>
        </article>
    @endforeach
</section>
@if ($activeTab === 'attendance')
    <p class="rounded-xl bg-sky-50 p-4 text-sm text-sky-800 dark:bg-sky-500/10 dark:text-sky-200">Setiap kartu merupakan satu sesi atau catatan presensi. Sesi lintas tengah malam mengikuti tanggal kerja. Foto presensi tidak dicatat pada sumber RFID. Populasi jadwal menggunakan keaktifan pegawai/divisi saat ini, sehingga angka historis dapat berbeda.</p>
@elseif ($activeTab === 'field-assistant')
    <p class="rounded-xl bg-sky-50 p-4 text-sm text-sky-800 dark:bg-sky-500/10 dark:text-sky-200">Jumlah penerima terkonfirmasi hanya berasal dari konfirmasi yang tercatat. Porsi rencana dan kedatangan aktual ditampilkan terpisah. Sumber konfirmasi belum menyediakan foto.</p>
@endif
@forelse ($finalModule['rows'] as $row)
    <article class="min-w-0 overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-950">
        <div class="border-b border-slate-200 p-5 dark:border-slate-800">
            <h3 class="break-words text-lg font-bold">{{ $row['title'] }}</h3>
            <dl class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($row['fields'] as $label => $value)
                    <div class="min-w-0"><dt class="text-xs text-slate-500 dark:text-slate-400">{{ $label }}</dt><dd class="mt-1 break-words text-sm font-semibold">{{ $value === null || $value === '' ? 'Belum diisi' : $value }}</dd></div>
                @endforeach
            </dl>
        </div>
        @foreach ($row['sections'] as $label => $items)
            <details class="border-b border-slate-100 last:border-0 dark:border-slate-800" @if($activeTab === 'field-assistant') open @endif>
                <summary class="cursor-pointer px-5 py-4 text-sm font-bold focus-visible:outline focus-visible:outline-sky-500">{{ $label }} <span class="ml-2 rounded-full bg-slate-100 px-2 py-1 text-xs dark:bg-slate-800">{{ count($items) }} item</span></summary>
                @if (count($items))
                    <div class="max-w-full overflow-x-auto pb-3">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-slate-50 text-xs text-slate-500 dark:bg-slate-900 dark:text-slate-400"><tr>@foreach (array_keys(reset($items)) as $heading)<th class="whitespace-nowrap px-5 py-3">{{ $heading }}</th>@endforeach</tr></thead>
                            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                                @foreach ($items as $item)
                                    <tr>@foreach ($item as $value)<td class="min-w-32 max-w-md px-5 py-3 align-top">
                                        @if (is_array($value) && isset($value['url']))
                                            <x-v3.documentation-button :url="$value['url']" :title="$value['title']" label="Lihat foto" />
                                        @else
                                            <span class="block max-h-72 overflow-auto whitespace-pre-wrap break-words">{{ $value === null || $value === '' ? 'Belum tersedia' : $value }}</span>
                                        @endif
                                    </td>@endforeach</tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="px-5 pb-5 text-sm text-slate-500 dark:text-slate-400">Belum ada data {{ mb_strtolower($label) }} pada laporan ini.</p>
                @endif
            </details>
        @endforeach
    </article>
@empty
    <div class="rounded-2xl border border-dashed border-slate-300 p-10 text-center dark:border-slate-700"><p class="font-bold">Belum ada laporan/data {{ $definition['label'] }}</p><p class="mt-2 text-sm text-slate-500 dark:text-slate-400">Tidak ada catatan pada tanggal terpilih. Ini tidak berarti pekerjaan selesai atau pegawai tidak hadir.</p></div>
@endforelse
<div>{{ $finalModule['pagination']->links() }}</div>
@if($activeTab === 'attendance' && $finalModule['notRecorded']->total())
    <section class="rounded-2xl border border-slate-200 bg-white p-5 dark:border-slate-800 dark:bg-slate-950">
        <h3 class="font-bold">Pegawai terjadwal tanpa catatan presensi</h3>
        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Belum ada sesi/izin/sakit yang tercatat pada tanggal kerja ini. Keadaan ini bukan penetapan alpa.</p>
        <ul class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-3">@foreach($finalModule['notRecorded'] as $person)<li class="rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-900">{{ $person->name }} · Belum presensi</li>@endforeach</ul>
        <div class="mt-4">{{ $finalModule['notRecorded']->links() }}</div>
    </section>
@endif
