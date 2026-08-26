@props([
    'unit',
    'navigation',
    'roleLabel',
    'title',
    'eyebrow' => 'Ruang kerja unit',
])

@php($activeNavigationKey = data_get(collect($navigation)->first(fn (array $group): bool => $group['active'] && ! $group['standalone']), 'key'))

<div
    x-data="{ sidebarOpen: false, profileOpen: false, documentationUrl: null, documentationTitle: '', documentationLoading: false, documentationError: false, openModule: @js($activeNavigationKey) }"
    x-init="if (!openModule) { openModule = localStorage.getItem('v3-sidebar-module') || null }"
    x-on:open-documentation.window="documentationLoading = true; documentationError = false; documentationUrl = $event.detail.url; documentationTitle = $event.detail.title || 'Dokumentasi'"
    x-on:keydown.escape.window="documentationUrl = null; documentationLoading = false; documentationError = false"
    class="min-h-screen"
>
    <div
        x-cloak
        x-show="sidebarOpen"
        x-transition.opacity
        class="fixed inset-0 z-40 bg-slate-950/50 backdrop-blur-sm lg:hidden"
        x-on:click="sidebarOpen = false"
    ></div>

    <aside
        class="fixed inset-y-0 left-0 z-50 flex w-[286px] -translate-x-full flex-col overflow-hidden bg-[#081d3a] text-white shadow-2xl transition-transform duration-300 lg:translate-x-0"
        :class="sidebarOpen && 'translate-x-0'"
    >
        <div class="absolute inset-0 opacity-20 [background-image:radial-gradient(circle_at_20%_10%,#38bdf8_0,transparent_25%),radial-gradient(circle_at_80%_90%,#84cc16_0,transparent_22%)]"></div>
        <div class="relative flex h-20 items-center gap-3 border-b border-white/10 px-6">
            <div class="v3-brand-mark grid size-11 place-items-center rounded-2xl bg-white shadow-lg shadow-sky-950/30">
                <img src="{{ asset('images/logo-bgn.png') }}" alt="BGN" class="size-9 object-contain">
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-lg font-bold tracking-tight">SPPG</span>
                </div>
                <p class="mt-0.5 text-xs text-slate-400">Sistem Operasional Terpadu</p>
            </div>
        </div>

        <nav class="relative mt-4 flex-1 overflow-y-auto px-4 pb-6">
            <p class="mb-2 px-3 text-[10px] font-semibold uppercase tracking-[.18em] text-slate-500">Modul kerja</p>
            @foreach ($navigation as $group)
                @if ($group['standalone'])
                    @php($item = $group['items'][0])
                    <a
                        href="{{ $item['url'] }}"
                        @if ($item['external']) target="_blank" rel="noopener" @else wire:navigate @endif
                        x-on:click="sidebarOpen = false"
                        @class([
                            'mb-1 flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-semibold transition',
                            'bg-cyan-300 text-[#071a34] shadow-lg shadow-cyan-950/20' => $item['active'],
                            'text-slate-300 hover:bg-white/[.07] hover:text-white' => ! $item['active'],
                        ])
                    >
                        <x-v3.icon :name="$group['icon']" class="size-[19px] shrink-0" />
                        <span class="min-w-0 flex-1 truncate">{{ $group['label'] }}</span>
                    </a>
                @else
                    <div class="mb-1">
                        <button
                            type="button"
                            x-on:click="openModule = openModule === @js($group['key']) ? null : @js($group['key']); localStorage.setItem('v3-sidebar-module', openModule || '')"
                            @class([
                                'flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-semibold transition',
                                'bg-white/[.08] text-white' => $group['active'],
                                'text-slate-300 hover:bg-white/[.07] hover:text-white' => ! $group['active'],
                            ])
                            :aria-expanded="openModule === @js($group['key'])"
                        >
                            <x-v3.icon :name="$group['icon']" class="size-[19px] shrink-0" />
                            <span class="min-w-0 flex-1 truncate">{{ $group['label'] }}</span>
                            <x-v3.icon
                                name="chevron-down"
                                class="size-4 shrink-0 text-slate-500 transition-transform duration-200"
                                x-bind:class="openModule === @js($group['key']) && 'rotate-180 text-cyan-300'"
                            />
                        </button>
                        <div
                            x-cloak
                            x-show="openModule === @js($group['key'])"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            class="mt-1 space-y-1 border-l border-white/10 pl-3 ml-[21px]"
                        >
                        @foreach ($group['items'] as $item)
                            <a
                                href="{{ $item['url'] }}"
                                @if ($item['external']) target="_blank" rel="noopener" @else wire:navigate @endif
                                x-on:click="sidebarOpen = false"
                                @class([
                                    'group flex items-center gap-2.5 rounded-xl px-3 py-2 text-[13px] font-medium transition',
                                    'bg-cyan-300 text-[#071a34] shadow-lg shadow-cyan-950/20' => $item['active'],
                                    'text-slate-300 hover:bg-white/[.07] hover:text-white' => ! $item['active'],
                                ])
                                >
                                <x-v3.icon :name="$item['icon']" class="size-[19px] shrink-0" />
                                <span class="min-w-0 flex-1 truncate">{{ $item['label'] }}</span>
                            </a>
                        @endforeach
                        </div>
                    </div>
                @endif
            @endforeach
        </nav>

        <div class="relative border-t border-white/10 p-4">
            <form method="POST" action="{{ route('v3.logout') }}">
                @csrf
                <button type="submit" class="flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-left text-sm font-medium text-slate-300 transition hover:bg-white/[.07] hover:text-white">
                    <x-v3.icon name="logout" class="size-[19px]" />
                    Keluar
                </button>
            </form>
        </div>
    </aside>

    <div class="min-h-screen lg:pl-[286px]">
        <header class="sticky top-0 z-30 border-b border-slate-200/80 bg-white/90 backdrop-blur-xl">
            <div class="flex h-20 items-center gap-3 px-4 sm:px-6 lg:px-8">
                <button x-on:click="sidebarOpen = true" class="grid size-10 place-items-center rounded-xl text-slate-600 transition hover:bg-slate-100 lg:hidden" aria-label="Buka menu">
                    <x-v3.icon name="menu" class="size-5" />
                </button>

                <div class="min-w-0 flex-1">
                    <p class="text-[10px] font-bold uppercase tracking-[.18em] text-sky-700">{{ $eyebrow }}</p>
                    <h1 class="mt-0.5 truncate text-lg font-bold tracking-tight text-slate-950 sm:text-xl">{{ $title }}</h1>
                </div>

                <x-v3.theme-toggle />

                <div class="relative" x-on:click.outside="profileOpen = false">
                    <button x-on:click="profileOpen = ! profileOpen" class="flex items-center gap-2 rounded-xl p-1.5 transition hover:bg-slate-100">
                        <span class="grid size-9 place-items-center rounded-xl bg-[#081d3a] text-sm font-bold text-white">{{ str(auth()->user()->name)->substr(0, 1)->upper() }}</span>
                        <span class="hidden max-w-36 text-left md:block">
                            <span class="block truncate text-xs font-bold text-slate-800">{{ auth()->user()->name }}</span>
                            <span class="block truncate text-[10px] text-slate-500">{{ $roleLabel }}</span>
                        </span>
                        <x-v3.icon name="chevron-down" class="hidden size-4 text-slate-400 md:block" />
                    </button>
                    <div x-cloak x-show="profileOpen" x-transition class="absolute right-0 mt-2 w-60 rounded-2xl border border-slate-200 bg-white p-2 shadow-xl shadow-slate-900/10">
                        <div class="border-b border-slate-100 px-3 py-2.5">
                            <p class="truncate text-sm font-bold text-slate-800">{{ auth()->user()->name }}</p>
                            <p class="truncate text-xs text-slate-500">{{ auth()->user()->email }}</p>
                        </div>
                        <form method="POST" action="{{ route('v3.logout') }}" class="mt-1">
                            @csrf
                            <button type="submit" class="flex w-full items-center gap-2 rounded-xl px-3 py-2 text-sm font-medium text-rose-700 transition hover:bg-rose-50">
                                <x-v3.icon name="logout" class="size-4" />
                                Keluar dari aplikasi
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
            {{ $slot }}
        </main>
    </div>

    <div
        x-cloak
        x-show="documentationUrl"
        x-transition.opacity
        class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm"
        role="dialog"
        aria-modal="true"
        aria-label="Pratinjau dokumentasi"
    >
        <button type="button" x-on:click="documentationUrl = null; documentationLoading = false; documentationError = false" class="absolute inset-0 cursor-default" aria-label="Tutup modal"></button>
        <div x-show="documentationUrl" x-transition.scale class="relative z-10 flex max-h-[92vh] w-full max-w-5xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-4">
                <div>
                    <p class="text-sm font-bold text-slate-900">Dokumentasi</p>
                    <p class="mt-0.5 text-xs text-slate-500" x-text="documentationTitle"></p>
                </div>
                <button type="button" x-on:click="documentationUrl = null; documentationLoading = false; documentationError = false" class="grid size-9 place-items-center rounded-xl bg-slate-100 text-lg font-bold text-slate-600 hover:bg-slate-200" aria-label="Tutup">×</button>
            </div>
            <div class="relative flex min-h-[240px] flex-1 items-center justify-center overflow-auto bg-slate-100 p-4">
                <div x-show="documentationLoading && ! documentationError" class="absolute inset-0 grid place-items-center">
                    <div class="flex flex-col items-center gap-3 text-sm font-semibold text-slate-600">
                        <span class="size-9 animate-spin rounded-full border-4 border-slate-300 border-t-sky-600"></span>
                        Memuat dokumentasi…
                    </div>
                </div>
                <div x-show="documentationError" class="max-w-sm rounded-2xl bg-white p-6 text-center shadow-sm">
                    <p class="font-bold text-slate-900">Foto tidak dapat ditampilkan</p>
                    <p class="mt-1 text-xs text-slate-500">Pastikan file masih tersedia dan koneksi perangkat stabil.</p>
                </div>
                <img
                    x-show="! documentationError"
                    x-bind:src="documentationUrl"
                    x-bind:alt="`Dokumentasi ${documentationTitle}`"
                    x-on:load="documentationLoading = false"
                    x-on:error="documentationLoading = false; documentationError = true"
                    class="max-h-[76vh] max-w-full rounded-xl object-contain shadow-sm"
                    x-bind:class="documentationLoading ? 'invisible' : 'visible'"
                >
            </div>
        </div>
    </div>
</div>
