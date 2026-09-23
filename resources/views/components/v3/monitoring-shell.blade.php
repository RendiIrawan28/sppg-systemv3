@props([
    'unit',
    'navigation',
    'roleLabel',
    'title',
    'eyebrow' => 'Pusat Monitoring Operasional',
])

<div
    x-data="{ sidebarOpen: false, profileOpen: false, documentationUrl: null, documentationTitle: '', documentationLoading: false, documentationError: false, photoTrigger: null, photoList: [], photoIndex: 0,
        closePhoto() { this.documentationUrl = null; this.documentationLoading = false; this.documentationError = false; if (this.photoTrigger?.isConnected) this.photoTrigger.focus(); },
        showPhoto(index) { if (!this.photoList[index]) return; this.photoIndex = index; this.documentationLoading = true; this.documentationError = false; this.documentationUrl = this.photoList[index].url; this.documentationTitle = this.photoList[index].title; }
    }"
    x-on:open-documentation.window="photoTrigger = document.activeElement; photoList = Array.from(photoTrigger?.closest('article')?.querySelectorAll('[data-documentation-url]') || []).map(el => ({ url: el.dataset.documentationUrl, title: el.dataset.documentationTitle })); if (!photoList.length) photoList = [$event.detail]; showPhoto(Math.max(0, photoList.findIndex(photo => photo.url === $event.detail.url))); $nextTick(() => $refs.photoClose.focus())"
    x-on:close-monitoring-documentation.window="closePhoto()"
    x-on:keydown.escape.window="closePhoto(); sidebarOpen = false"
    class="min-h-screen overflow-x-hidden bg-[#f4f7fb] text-slate-950 dark:bg-[#07111f] dark:text-slate-100"
>
    <div x-cloak x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-40 bg-slate-950/60 backdrop-blur-sm lg:hidden" x-on:click="sidebarOpen = false"></div>

    <aside
        class="fixed inset-y-0 left-0 z-50 flex w-[276px] -translate-x-full flex-col overflow-hidden bg-[#071a34] text-white shadow-2xl transition-transform duration-300 lg:translate-x-0"
        :class="sidebarOpen && 'translate-x-0'"
    >
        <div class="absolute inset-0 opacity-20 [background-image:radial-gradient(circle_at_10%_5%,#38bdf8_0,transparent_24%),radial-gradient(circle_at_90%_95%,#84cc16_0,transparent_20%)]"></div>
        <div class="relative flex h-20 items-center gap-3 border-b border-white/10 px-5">
            <div class="grid size-11 shrink-0 place-items-center rounded-2xl bg-white shadow-lg shadow-sky-950/30">
                <img src="{{ asset('images/logo-bgn.png') }}" alt="BGN" class="size-9 object-contain">
            </div>
            <div class="min-w-0">
                <p class="truncate text-base font-bold tracking-tight">SPPG Monitoring</p>
                <p class="mt-0.5 truncate text-[11px] text-slate-400">Pusat Monitoring Operasional</p>
            </div>
        </div>

        <nav class="relative flex-1 overflow-y-auto px-4 py-5">
            <p class="mb-3 px-3 text-[10px] font-bold uppercase tracking-[.2em] text-slate-500">Monitoring</p>
            <div class="space-y-1">
                @foreach ($navigation as $item)
                    <a
                        href="{{ $item['url'] }}"
                        wire:navigate
                        x-on:click="sidebarOpen = false"
                        @class([
                            'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition',
                            'bg-cyan-300 text-[#071a34] shadow-lg shadow-cyan-950/20' => $item['active'],
                            'text-slate-300 hover:bg-white/[.07] hover:text-white' => ! $item['active'],
                        ])
                    >
                        <x-v3.icon :name="$item['icon']" class="size-[19px] shrink-0" />
                        <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </div>
        </nav>

        <div class="relative space-y-2 border-t border-white/10 p-4">
            <a href="{{ route('v3.dashboard') }}" wire:navigate class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold text-cyan-200 transition hover:bg-white/[.07] hover:text-white">
                <x-v3.icon name="arrow-left" class="size-[19px]" />
                Kembali ke Dashboard SPPG
            </a>
            <form method="POST" action="{{ route('v3.logout') }}">
                @csrf
                <button type="submit" class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-slate-400 transition hover:bg-white/[.07] hover:text-white">
                    <x-v3.icon name="logout" class="size-[19px]" />
                    Keluar
                </button>
            </form>
        </div>
    </aside>

    <div class="min-h-screen lg:pl-[276px]">
        <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl dark:border-slate-800 dark:bg-[#0b1728]/90">
            <div class="flex h-20 items-center gap-3 px-4 sm:px-6 lg:px-8">
                <button type="button" x-on:click="sidebarOpen = true" class="grid size-10 shrink-0 place-items-center rounded-xl text-slate-600 transition hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800 lg:hidden" aria-label="Buka menu Monitoring">
                    <x-v3.icon name="menu" class="size-5" />
                </button>

                <div class="min-w-0 flex-1">
                    <p class="text-[10px] font-bold uppercase tracking-[.18em] text-sky-700 dark:text-sky-300">{{ $eyebrow }}</p>
                    <div class="mt-0.5 flex min-w-0 items-center gap-2">
                        <h1 class="truncate text-lg font-bold tracking-tight text-slate-950 dark:text-slate-50 sm:text-xl">{{ $title }}</h1>
                        <span class="hidden truncate text-xs font-semibold text-slate-400 sm:inline">· {{ $unit->name }}</span>
                    </div>
                </div>

                <a href="{{ route('v3.dashboard') }}" wire:navigate class="hidden h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 text-xs font-bold text-slate-600 transition hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:bg-slate-800 xl:inline-flex">
                    <x-v3.icon name="arrow-left" class="size-4" />
                    Dashboard
                </a>

                <x-v3.theme-toggle />

                <div class="relative" x-on:click.outside="profileOpen = false">
                    <button type="button" x-on:click="profileOpen = ! profileOpen" class="flex items-center gap-2 rounded-xl p-1.5 transition hover:bg-slate-100 dark:hover:bg-slate-800">
                        <span class="grid size-9 place-items-center rounded-xl bg-[#081d3a] text-sm font-bold text-white ring-1 ring-white/10">{{ str(auth()->user()->name)->substr(0, 1)->upper() }}</span>
                        <span class="hidden max-w-36 text-left md:block">
                            <span class="block truncate text-xs font-bold text-slate-800 dark:text-slate-100">{{ auth()->user()->name }}</span>
                            <span class="block truncate text-[10px] text-slate-500 dark:text-slate-400">{{ $roleLabel }}</span>
                        </span>
                        <x-v3.icon name="chevron-down" class="hidden size-4 text-slate-400 md:block" />
                    </button>
                    <div x-cloak x-show="profileOpen" x-transition class="absolute right-0 mt-2 w-60 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10 dark:border-slate-700 dark:bg-slate-900">
                        <div class="border-b border-slate-100 px-3 py-2.5 dark:border-slate-800">
                            <p class="truncate text-sm font-bold text-slate-800 dark:text-slate-100">{{ auth()->user()->name }}</p>
                            <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ auth()->user()->email }}</p>
                        </div>
                        <form method="POST" action="{{ route('v3.logout') }}" class="mt-1">
                            @csrf
                            <button type="submit" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium text-rose-700 transition hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-rose-500/10">
                                <x-v3.icon name="logout" class="size-4" />
                                Keluar dari aplikasi
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="min-w-0 px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            {{ $slot }}
        </main>
    </div>

    <div x-cloak x-show="documentationUrl" x-trap.inert.noscroll="documentationUrl" x-transition.opacity class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/80 p-4 backdrop-blur-sm" role="dialog" aria-modal="true" aria-label="Pratinjau dokumentasi">
        <button type="button" x-on:click="closePhoto()" tabindex="-1" class="absolute inset-0 cursor-default" aria-label="Tutup modal"></button>
        <div x-show="documentationUrl" x-transition.scale class="relative z-10 flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-slate-900">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-700">
                <div><p class="text-sm font-bold text-slate-900 dark:text-slate-100">Dokumentasi</p><p class="mt-0.5 text-xs text-slate-500 dark:text-slate-400" x-text="documentationTitle"></p></div>
                <button x-ref="photoClose" type="button" x-on:click="closePhoto()" class="grid size-9 place-items-center rounded-xl bg-slate-100 text-lg font-bold text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700" aria-label="Tutup">×</button>
            </div>
            <div class="relative flex min-h-[240px] flex-1 items-center justify-center overflow-auto bg-slate-100 p-4 dark:bg-slate-950">
                <div x-show="documentationLoading && ! documentationError" class="absolute inset-0 grid place-items-center"><div class="flex flex-col items-center gap-3 text-sm font-semibold text-slate-600 dark:text-slate-300"><span class="size-9 animate-spin rounded-full border-4 border-slate-300 border-t-sky-600 dark:border-slate-700 dark:border-t-sky-400"></span>Memuat dokumentasi…</div></div>
                <div x-show="documentationError" class="max-w-sm rounded-2xl bg-white p-6 text-center shadow-sm dark:bg-slate-900"><p class="font-bold text-slate-900 dark:text-slate-100">Foto tidak dapat ditampilkan</p><p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Pastikan file masih tersedia dan koneksi perangkat stabil.</p></div>
                <img x-show="! documentationError" x-bind:src="documentationUrl" x-bind:alt="`Dokumentasi ${documentationTitle}`" x-on:load="documentationLoading = false" x-on:error="documentationLoading = false; documentationError = true" class="max-h-[76vh] max-w-full rounded-xl object-contain shadow-sm" x-bind:class="documentationLoading ? 'invisible' : 'visible'">
            </div>
            <div x-show="photoList.length > 1" class="flex items-center justify-between gap-3 border-t border-slate-200 p-3 text-sm dark:border-slate-700">
                <button type="button" x-on:click="showPhoto(photoIndex - 1)" :disabled="photoIndex === 0" class="rounded-lg bg-slate-100 px-3 py-2 disabled:opacity-40 dark:bg-slate-800">Sebelumnya</button>
                <span x-text="(photoIndex + 1) + ' / ' + photoList.length"></span>
                <button type="button" x-on:click="showPhoto(photoIndex + 1)" :disabled="photoIndex >= photoList.length - 1" class="rounded-lg bg-slate-100 px-3 py-2 disabled:opacity-40 dark:bg-slate-800">Berikutnya</button>
            </div>
        </div>
    </div>
</div>
