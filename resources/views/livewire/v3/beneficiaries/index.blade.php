<x-v3.shell :$unit :$navigation :$roleLabel title="Penerima Manfaat" eyebrow="Data master">
    <div class="mx-auto max-w-[1500px] space-y-5">
        @if (session('v3.status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('v3.status') }}</div>
        @endif

        <section class="flex flex-col justify-between gap-5 rounded-[24px] bg-[#081d3a] p-6 text-white shadow-xl shadow-slate-900/10 sm:flex-row sm:items-end sm:p-7">
            <div>
                <div class="flex items-center gap-2 text-xs font-semibold text-cyan-200">
                    <span class="size-2 rounded-full bg-lime-300"></span>
                    Data penerima manfaat
                </div>
                <h2 class="mt-4 text-2xl font-bold tracking-[-.03em] sm:text-3xl">Data penerima yang mudah ditelusuri.</h2>
                <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">Cari berdasarkan nama, kode, nomor eksternal, atau kelompok tanpa memuat ulang halaman.</p>
            </div>
            <div class="flex flex-wrap items-end justify-end gap-3">
                @if (auth()->user()->is_super_admin || auth()->user()->can('beneficiary_periods.view'))
                    <a wire:navigate href="{{ route('v3.beneficiary-periods.index') }}" class="inline-flex h-11 items-center gap-2 rounded-xl border border-white/15 bg-white/10 px-4 text-xs font-bold text-white transition hover:bg-white/15">
                        <x-v3.icon name="calendar" class="size-4" /> Periode
                    </a>
                @endif
                @if (auth()->user()->is_super_admin || auth()->user()->can('beneficiaries.import'))
                    <a wire:navigate href="{{ route('v3.beneficiaries.import') }}" class="inline-flex h-11 items-center gap-2 rounded-xl border border-white/15 bg-white/10 px-4 text-xs font-bold text-white transition hover:bg-white/15">
                        <x-v3.icon name="upload" class="size-4" /> Impor
                    </a>
                @endif
                @if (auth()->user()->is_super_admin || auth()->user()->can('beneficiaries.create'))
                    <a wire:navigate href="{{ route('v3.beneficiaries.create') }}" class="inline-flex h-11 items-center gap-2 rounded-xl bg-cyan-300 px-4 text-xs font-bold text-[#071a34] shadow-lg shadow-cyan-950/20 transition hover:bg-cyan-200">
                        <x-v3.icon name="plus" class="size-4" /> Tambah penerima
                    </a>
                @endif
                <div class="min-w-28 rounded-2xl border border-white/10 bg-white/[.07] px-4 py-3">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Aktif</p>
                    <p class="mt-1 text-xl font-bold">{{ number_format($activeCount, 0, ',', '.') }}</p>
                </div>
                <div class="min-w-28 rounded-2xl border border-cyan-300/20 bg-cyan-300/10 px-4 py-3">
                    <p class="text-[10px] font-bold uppercase tracking-wider text-cyan-200/70">Total</p>
                    <p class="mt-1 text-xl font-bold text-cyan-100">{{ number_format($totalCount, 0, ',', '.') }}</p>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-sm">
            <div class="border-b border-slate-100 p-4 sm:p-5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-center">
                    <div class="relative flex-1">
                        <span class="pointer-events-none absolute inset-y-0 left-0 grid w-11 place-items-center text-slate-400">
                            <x-v3.icon name="search" class="size-[18px]" />
                        </span>
                        <input
                            wire:model.live.debounce.350ms="search"
                            type="search"
                            placeholder="Cari nama, kode, ID eksternal, atau kelompok..."
                            class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50/50 pl-11 pr-4 text-sm outline-none transition placeholder:text-slate-400 focus:border-sky-400 focus:bg-white focus:ring-4 focus:ring-sky-100"
                        >
                    </div>
                    <div class="flex gap-2">
                        <select wire:model.live="status" class="h-11 min-w-40 rounded-xl border border-slate-200 bg-white px-3 text-sm font-medium text-slate-600 outline-none focus:border-sky-400 focus:ring-4 focus:ring-sky-100">
                            <option value="all">Semua status</option>
                            <option value="active">Aktif</option>
                            <option value="inactive">Tidak aktif</option>
                        </select>
                        @if ($search !== '' || $status !== 'active')
                            <button wire:click="clearFilters" class="h-11 rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-600 transition hover:bg-slate-50">Reset</button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="relative overflow-x-auto">
                <div wire:loading.flex wire:target="search,status,clearFilters" class="absolute inset-0 z-10 items-start justify-center bg-white/70 pt-20 backdrop-blur-[1px]">
                    <span class="rounded-full bg-[#081d3a] px-3 py-1.5 text-xs font-semibold text-white shadow-lg">Memuat data...</span>
                </div>

                <table class="w-full min-w-[860px] text-left">
                    <thead>
                        <tr class="border-b border-slate-100 bg-slate-50/70 text-[10px] font-bold uppercase tracking-[.12em] text-slate-400">
                            <th class="px-5 py-3.5">Penerima</th>
                            <th class="px-5 py-3.5">Kategori</th>
                            <th class="px-5 py-3.5">Tujuan / kelompok</th>
                            <th class="px-5 py-3.5">Mulai layanan</th>
                            <th class="px-5 py-3.5">Status</th>
                            <th class="px-5 py-3.5 text-right">Aksi</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($beneficiaries as $beneficiary)
                            <tr wire:key="beneficiary-{{ $beneficiary->id }}" class="transition hover:bg-sky-50/30">
                                <td class="px-5 py-4">
                                    <div class="flex items-center gap-3">
                                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-sky-50 text-sm font-bold text-sky-700 ring-1 ring-sky-100">{{ str($beneficiary->name)->substr(0, 1)->upper() }}</span>
                                        <div class="min-w-0">
                                            <p class="max-w-64 truncate text-sm font-bold text-slate-800">{{ $beneficiary->name }}</p>
                                            <p class="mt-0.5 text-xs text-slate-400">{{ $beneficiary->external_id ?: $beneficiary->code }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-600">{{ $beneficiary->category?->name ?? '—' }}</td>
                                <td class="px-5 py-4">
                                    <p class="max-w-52 truncate text-sm font-medium text-slate-700">{{ $beneficiary->beneficiaryable?->name ?? 'Belum ditentukan' }}</p>
                                    <p class="mt-0.5 text-xs text-slate-400">{{ $beneficiary->group_name ?: 'Tanpa kelompok' }}</p>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-600">{{ $beneficiary->start_date?->translatedFormat('d M Y') ?? '—' }}</td>
                                <td class="px-5 py-4">
                                    <span @class([
                                        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[10px] font-bold',
                                        'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100' => $beneficiary->is_active,
                                        'bg-slate-100 text-slate-500 ring-1 ring-slate-200' => ! $beneficiary->is_active,
                                    ])>
                                        <span @class(['size-1.5 rounded-full', 'bg-emerald-500' => $beneficiary->is_active, 'bg-slate-400' => ! $beneficiary->is_active])></span>
                                        {{ $beneficiary->is_active ? 'Aktif' : 'Tidak aktif' }}
                                    </span>
                                </td>
                                <td class="px-5 py-4">
                                    <div class="flex justify-end gap-2">
                                        @if (auth()->user()->is_super_admin || auth()->user()->can('beneficiaries.update'))
                                            <button
                                                wire:click="toggleStatus({{ $beneficiary->id }})"
                                                wire:confirm="{{ $beneficiary->is_active ? 'Nonaktifkan penerima ini?' : 'Aktifkan kembali penerima ini?' }}"
                                                class="rounded-lg border border-slate-200 px-2.5 py-1.5 text-[10px] font-bold text-slate-600 transition hover:bg-slate-50"
                                            >{{ $beneficiary->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button>
                                            <a wire:navigate href="{{ route('v3.beneficiaries.edit', $beneficiary) }}" class="grid size-8 place-items-center rounded-lg bg-sky-50 text-sky-700 ring-1 ring-sky-100" aria-label="Ubah {{ $beneficiary->name }}">
                                                <x-v3.icon name="pencil" class="size-4" />
                                            </a>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-16 text-center">
                                    <span class="mx-auto grid size-12 place-items-center rounded-2xl bg-slate-100 text-slate-400">
                                        <x-v3.icon name="search" class="size-5" />
                                    </span>
                                    <p class="mt-4 text-sm font-bold text-slate-700">Data tidak ditemukan</p>
                                    <p class="mt-1 text-xs text-slate-400">Coba ubah kata pencarian atau filter status.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($beneficiaries->hasPages())
                <div class="border-t border-slate-100 px-5 py-4">
                    {{ $beneficiaries->links() }}
                </div>
            @endif
        </section>

    </div>
</x-v3.shell>
