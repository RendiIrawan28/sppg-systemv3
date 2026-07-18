<main class="relative grid min-h-screen overflow-hidden lg:grid-cols-[1.08fr_.92fr]">
    <div class="fixed right-4 top-4 z-30 sm:right-6 sm:top-6">
        <x-v3.theme-toggle class="border-white/20 bg-white/90 backdrop-blur" />
    </div>
    <div class="pointer-events-none absolute inset-0 opacity-30 [background-image:radial-gradient(circle_at_15%_20%,#0ea5e9_0,transparent_23%),radial-gradient(circle_at_70%_90%,#84cc16_0,transparent_20%)]"></div>
    <div class="pointer-events-none absolute inset-0 opacity-[.05] [background-image:linear-gradient(rgba(255,255,255,.8)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.8)_1px,transparent_1px)] [background-size:48px_48px]"></div>

    <section class="relative hidden min-h-screen flex-col justify-between p-12 text-white lg:flex xl:p-16">
        <div class="flex items-center gap-3">
            <div class="v3-brand-mark grid size-12 place-items-center rounded-2xl bg-white shadow-2xl shadow-slate-950/30">
                <img src="{{ asset('images/logo-bgn.png') }}" alt="BGN" class="size-10 object-contain">
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-xl font-bold tracking-tight">SPPG</span>
                    <span class="rounded-full bg-cyan-300/15 px-2 py-0.5 text-[10px] font-bold tracking-[.18em] text-cyan-200 ring-1 ring-cyan-200/20">V3</span>
                </div>
                <p class="text-xs text-slate-400">Sistem Operasional Terpadu</p>
            </div>
        </div>

        <div class="max-w-xl pb-10">
            <span class="inline-flex items-center gap-2 rounded-full border border-cyan-300/20 bg-cyan-300/10 px-3 py-1 text-xs font-semibold text-cyan-200">
                <span class="size-1.5 rounded-full bg-cyan-300"></span>
                Native Laravel + Livewire
            </span>
            <h1 class="mt-6 text-5xl font-bold leading-[1.08] tracking-[-.04em] xl:text-6xl">
                Satu ruang kerja untuk seluruh alur layanan gizi.
            </h1>
            <p class="mt-6 max-w-lg text-base leading-7 text-slate-300">
                Dari perencanaan penerima sampai laporan operasional, setiap divisi bekerja pada data unit yang sama dan jejak proses yang jelas.
            </p>
            <div class="mt-10 grid grid-cols-3 gap-3">
                @foreach ([['01', 'Terintegrasi'], ['02', 'Berbasis peran'], ['03', 'Siap bertumbuh']] as [$number, $label])
                    <div class="rounded-2xl border border-white/10 bg-white/[.05] p-4 backdrop-blur-sm">
                        <p class="text-xs font-bold text-cyan-300">{{ $number }}</p>
                        <p class="mt-3 text-sm font-medium text-slate-200">{{ $label }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        <p class="text-xs text-slate-500">Badan Gizi Nasional · Sistem SPPG</p>
    </section>

    <section class="relative flex min-h-screen items-center justify-center bg-[#f6f8fc] px-5 py-10 sm:px-10 lg:rounded-l-[40px] lg:shadow-[-30px_0_80px_rgba(2,12,27,.22)]">
        <div class="w-full max-w-[430px]">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <div class="v3-brand-mark grid size-11 place-items-center rounded-2xl bg-white shadow-lg">
                    <img src="{{ asset('images/logo-bgn.png') }}" alt="BGN" class="size-9 object-contain">
                </div>
                <div>
                    <span class="font-bold text-[#081d3a]">SPPG V3</span>
                    <p class="text-xs text-slate-500">Sistem Operasional Terpadu</p>
                </div>
            </div>

            <div class="mb-8">
                <p class="text-xs font-bold uppercase tracking-[.18em] text-sky-700">Selamat datang kembali</p>
                <h2 class="mt-3 text-3xl font-bold tracking-[-.03em] text-slate-950">Masuk ke ruang kerja</h2>
                <p class="mt-2 text-sm leading-6 text-slate-500">Gunakan email atau nomor pegawai yang terdaftar pada sistem.</p>
            </div>

            <form wire:submit="authenticate" class="space-y-5">
                <div>
                    <label for="login" class="mb-2 block text-sm font-semibold text-slate-700">Email atau nomor pegawai</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 grid w-12 place-items-center text-slate-400">
                            <x-v3.icon name="users" class="size-[18px]" />
                        </span>
                        <input
                            wire:model="login"
                            id="login"
                            type="text"
                            autocomplete="username"
                            autofocus
                            placeholder="nama@sppg.go.id"
                            @class([
                                'h-12 w-full rounded-xl border bg-white pl-12 pr-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:ring-4',
                                'border-rose-300 focus:border-rose-400 focus:ring-rose-100' => $errors->has('login'),
                                'border-slate-200 focus:border-sky-400 focus:ring-sky-100' => ! $errors->has('login'),
                            ])
                        >
                    </div>
                    @error('login') <p class="mt-2 text-xs font-medium text-rose-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="password" class="mb-2 block text-sm font-semibold text-slate-700">Kata sandi</label>
                    <input
                        wire:model="password"
                        id="password"
                        type="password"
                        autocomplete="current-password"
                        placeholder="Masukkan kata sandi"
                        @class([
                            'h-12 w-full rounded-xl border bg-white px-4 text-sm text-slate-900 outline-none transition placeholder:text-slate-400 focus:ring-4',
                            'border-rose-300 focus:border-rose-400 focus:ring-rose-100' => $errors->has('password'),
                            'border-slate-200 focus:border-sky-400 focus:ring-sky-100' => ! $errors->has('password'),
                        ])
                    >
                    @error('password') <p class="mt-2 text-xs font-medium text-rose-600">{{ $message }}</p> @enderror
                </div>

                <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-600">
                    <input wire:model="remember" type="checkbox" class="size-4 rounded border-slate-300 text-sky-700 focus:ring-sky-500">
                    Ingat saya di perangkat ini
                </label>

                <button type="submit" class="group flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#081d3a] px-4 text-sm font-bold text-white shadow-lg shadow-slate-900/15 transition hover:bg-[#0d2b54] disabled:cursor-wait disabled:opacity-70" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="authenticate">Masuk ke aplikasi</span>
                    <span wire:loading wire:target="authenticate">Memeriksa akun...</span>
                    <span wire:loading.remove wire:target="authenticate" class="transition-transform group-hover:translate-x-1">→</span>
                </button>
            </form>

            <div class="mt-8 rounded-2xl border border-slate-200 bg-white p-4 text-xs leading-5 text-slate-500">
                Akses mengikuti unit dan peran yang telah ditetapkan administrator SPPG. Hubungi admin unit apabila akun belum dapat digunakan.
            </div>
        </div>
    </section>
</main>
