<x-v3.shell :$unit :$navigation :$roleLabel title="Dashboard" eyebrow="Ringkasan operasional">
    <div class="mx-auto max-w-[1500px] space-y-6">
        <section class="relative overflow-hidden rounded-[28px] bg-[#081d3a] p-6 text-white shadow-xl shadow-slate-900/10 sm:p-8">
            <div class="absolute inset-0 opacity-30 [background-image:radial-gradient(circle_at_85%_20%,#22d3ee_0,transparent_23%),radial-gradient(circle_at_75%_110%,#84cc16_0,transparent_28%)]"></div>
            <div class="absolute -right-12 -top-12 size-56 rounded-full border border-white/10"></div>
            <div class="absolute -right-2 -top-2 size-32 rounded-full border border-white/10"></div>
            <div class="relative flex flex-col justify-between gap-8 lg:flex-row lg:items-end">
                <div class="max-w-2xl">
                    <div class="flex items-center gap-2 text-xs font-semibold text-cyan-200">
                        <span class="size-2 rounded-full bg-lime-300 shadow-[0_0_0_5px_rgba(190,242,100,.12)]"></span>
                        {{ now()->translatedFormat('l, d F Y') }}
                    </div>
                    <h2 class="mt-5 text-3xl font-bold tracking-[-.035em] sm:text-4xl">Selamat bekerja, {{ str(auth()->user()->name)->before(' ') }}.</h2>
                    <p class="mt-3 max-w-xl text-sm leading-6 text-slate-300 sm:text-base">Pantau kesiapan layanan {{ $unit->name }} dan tindak lanjuti hal penting dari satu tempat.</p>
                </div>
            </div>
        </section>

        @if ($cards !== [])
            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ($cards as $card)
                    @php
                        $tones = [
                            'sky' => 'bg-sky-50 text-sky-700 ring-sky-100',
                            'emerald' => 'bg-emerald-50 text-emerald-700 ring-emerald-100',
                            'amber' => 'bg-amber-50 text-amber-700 ring-amber-100',
                            'rose' => 'bg-rose-50 text-rose-700 ring-rose-100',
                            'violet' => 'bg-violet-50 text-violet-700 ring-violet-100',
                            'slate' => 'bg-slate-100 text-slate-600 ring-slate-200',
                        ];
                    @endphp
                    <article class="group relative overflow-hidden rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg hover:shadow-slate-900/5">
                        @if ($card['url']) <a href="{{ $card['url'] }}" wire:navigate class="absolute inset-0 z-10" aria-label="Buka {{ $card['label'] }}"></a> @endif
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold text-slate-500">{{ $card['label'] }}</p>
                                <p class="mt-2 text-3xl font-bold tracking-[-.04em] text-slate-950">{{ is_numeric($card['value']) ? number_format($card['value'], 0, ',', '.') : $card['value'] }}</p>
                            </div>
                            <span class="grid size-10 place-items-center rounded-xl ring-1 {{ $tones[$card['tone']] ?? $tones['slate'] }}">
                                <x-v3.icon :name="$card['icon']" class="size-5" />
                            </span>
                        </div>
                        <p class="mt-3 line-clamp-1 text-xs text-slate-500">{{ $card['detail'] }}</p>
                    </article>
                @endforeach
            </section>
        @endif

        <div class="grid gap-6 xl:grid-cols-[1.3fr_.7fr]">
            <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-[.17em] text-sky-700">Hari ini</p>
                        <h3 class="mt-1 text-lg font-bold tracking-tight text-slate-950">Denyut operasional</h3>
                        <p class="mt-1 text-xs text-slate-500">Jumlah dokumen kerja yang terjadwal pada setiap tahap.</p>
                    </div>
                    <span class="rounded-full bg-slate-100 px-3 py-1 text-[10px] font-bold text-slate-500">LIVE DATA</span>
                </div>

                @if ($pulse !== [])
                    <div class="mt-7 grid gap-2 sm:grid-cols-3 lg:grid-cols-6 xl:grid-cols-3 2xl:grid-cols-6">
                        @foreach ($pulse as $index => $stage)
                            <div class="relative rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
                                <div class="flex items-center justify-between">
                                    <span @class([
                                        'size-2 rounded-full',
                                        'bg-emerald-500 shadow-[0_0_0_4px_rgba(16,185,129,.12)]' => $stage['state'] === 'ready',
                                        'bg-slate-300' => $stage['state'] === 'empty',
                                    ])></span>
                                    <span class="text-[10px] font-bold text-slate-400">{{ str_pad($index + 1, 2, '0', STR_PAD_LEFT) }}</span>
                                </div>
                                <p class="mt-6 text-2xl font-bold text-slate-900">{{ $stage['count'] }}</p>
                                <p class="mt-1 text-xs font-medium text-slate-500">{{ $stage['label'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="mt-6 rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-8 text-center">
                        <p class="text-sm font-semibold text-slate-600">Tidak ada tahap operasional pada peran ini.</p>
                        <p class="mt-1 text-xs text-slate-400">Ringkasan akan menyesuaikan permission akun secara otomatis.</p>
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-v3.shell>
