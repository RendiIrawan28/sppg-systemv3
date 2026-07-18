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
                <div class="grid grid-cols-2 gap-3 sm:flex">
                    <div class="rounded-2xl border border-white/10 bg-white/[.07] px-4 py-3 backdrop-blur-sm">
                        <p class="text-[10px] font-bold uppercase tracking-[.15em] text-slate-400">Unit</p>
                        <p class="mt-1 text-sm font-semibold">{{ $unit->code }}</p>
                    </div>
                    <div class="rounded-2xl border border-cyan-300/20 bg-cyan-300/10 px-4 py-3 backdrop-blur-sm">
                        <p class="text-[10px] font-bold uppercase tracking-[.15em] text-cyan-200/70">Platform</p>
                        <p class="mt-1 text-sm font-semibold text-cyan-100">Native Livewire</p>
                    </div>
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

            <aside class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-cyan-100 to-sky-50 p-6 ring-1 ring-sky-100">
                <div class="absolute -bottom-16 -right-16 size-48 rounded-full bg-sky-300/20"></div>
                <p class="relative text-[10px] font-bold uppercase tracking-[.17em] text-sky-800">Migrasi selesai</p>
                <h3 class="relative mt-2 text-xl font-bold tracking-tight text-[#081d3a]">Seluruh operasional kini native V3.</h3>
                <p class="relative mt-3 text-sm leading-6 text-sky-950/70">Rencana lapangan, dapur, distribusi, pencucian, kebersihan, dan master data berjalan pada Laravel + Livewire tanpa panel atau tenant lama.</p>
                <a wire:navigate href="{{ route('v3.field.plans.index') }}" class="relative mt-6 inline-flex items-center gap-2 rounded-xl bg-[#081d3a] px-4 py-2.5 text-xs font-bold text-white shadow-lg shadow-sky-900/15 transition hover:bg-[#0d2b54]">
                    Buka rencana lapangan
                    <x-v3.icon name="arrow-up-right" class="size-4" />
                </a>
            </aside>
        </div>

        <section class="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm sm:p-6">
            <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-end">
                <div>
                    <p class="text-[10px] font-bold uppercase tracking-[.17em] text-sky-700">Peta migrasi</p>
                    <h3 class="mt-1 text-lg font-bold tracking-tight text-slate-950">Workspace yang tersedia untuk peran Anda</h3>
                </div>
                <p class="text-xs text-slate-400">Setiap modul dipindahkan tanpa mengganti sumber data bisnis.</p>
            </div>
            <div class="mt-5 grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach ($roadmap as $item)
                    <article class="rounded-2xl border border-slate-100 bg-slate-50/60 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h4 class="text-sm font-bold text-slate-800">{{ $item['label'] }}</h4>
                                <p class="mt-1 text-xs leading-5 text-slate-500">{{ $item['description'] }}</p>
                            </div>
                            <span @class([
                                'shrink-0 rounded-full px-2 py-1 text-[9px] font-bold uppercase tracking-wider',
                                'bg-emerald-100 text-emerald-700' => $item['state'] === 'active',
                                'bg-sky-100 text-sky-700' => $item['state'] === 'next',
                                'bg-slate-200 text-slate-600' => $item['state'] === 'queued',
                            ])>
                                {{ match ($item['state']) { 'active' => 'V3 aktif', 'next' => 'Berikutnya', default => 'Antrean' } }}
                            </span>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
</x-v3.shell>
