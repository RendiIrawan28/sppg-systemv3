<x-v3.shell :$unit :$navigation :$roleLabel title="Pengadaan Bahan" eyebrow="Kebutuhan → supplier → harga → pesanan">
    <div class="mx-auto max-w-[1450px] space-y-5">
        @if (session('v3.status'))<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ session('v3.status') }}</div>@endif

        <section class="relative overflow-hidden rounded-[28px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7">
            <div class="absolute -right-24 -top-24 size-80 rounded-full bg-cyan-300/10"></div>
            <div class="relative flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                <div><span class="rounded-full bg-cyan-300/10 px-3 py-1 text-[10px] font-bold uppercase tracking-[.16em] text-cyan-200 ring-1 ring-cyan-300/20">Workspace lintas peran</span><h2 class="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">Kendalikan pembelian dari satu antrean.</h2><p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">Permintaan selalu berasal dari kebutuhan bahan. Gudang memilih supplier, Keuangan mengisi dan memverifikasi harga, Kepala SPPG mengunci harga final.</p></div>
                <a wire:navigate href="{{ route('v3.nutrition.requirements.index') }}" class="inline-flex h-11 items-center gap-2 rounded-xl border border-white/15 bg-white/10 px-4 text-xs font-bold text-white"><x-v3.icon name="calculator" class="size-4" /> Sumber kebutuhan</a>
            </div>
            <div class="relative mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-white/10 bg-white/[.07] px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-slate-400">Total dokumen</p><p class="mt-1 text-xl font-bold">{{ number_format($requestCount, 0, ',', '.') }}</p></div>
                <div class="rounded-2xl border border-amber-300/20 bg-amber-300/10 px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-amber-200/70">Perlu tindakan</p><p class="mt-1 text-xl font-bold text-amber-100">{{ number_format($waitingCount, 0, ',', '.') }}</p></div>
                <div class="rounded-2xl border border-cyan-300/20 bg-cyan-300/10 px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-cyan-200/70">Sudah dipesan</p><p class="mt-1 text-xl font-bold text-cyan-100">{{ number_format($orderedCount, 0, ',', '.') }}</p></div>
                <div class="rounded-2xl border border-white/10 bg-white/[.07] px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-slate-400">Nilai estimasi</p><p class="mt-1 text-xl font-bold">Rp {{ number_format($totalAmount, 0, ',', '.') }}</p></div>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:p-5">
                <div class="relative flex-1"><span class="pointer-events-none absolute inset-y-0 left-0 grid w-11 place-items-center text-slate-400"><x-v3.icon name="search" class="size-[18px]" /></span><input wire:model.live.debounce.350ms="search" type="search" placeholder="Cari nomor, kebutuhan, atau bahan..." class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50/50 pl-11 pr-4 text-sm"></div>
                <select wire:model.live="status" class="h-11 min-w-60 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-600"><option value="all">Semua status</option>@foreach ($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </div>
            <div class="overflow-x-auto"><table class="w-full min-w-[1020px] text-left"><thead><tr class="border-b border-slate-100 bg-slate-50/70 text-[10px] font-bold uppercase tracking-wider text-slate-400"><th class="px-5 py-3.5">Permintaan</th><th class="px-5 py-3.5">Kebutuhan bahan</th><th class="px-5 py-3.5">Dibutuhkan</th><th class="px-5 py-3.5 text-center">Bahan</th><th class="px-5 py-3.5 text-right">Estimasi</th><th class="px-5 py-3.5">Status</th><th class="px-5 py-3.5 text-right">Aksi</th></tr></thead><tbody class="divide-y divide-slate-100">
                @forelse ($requests as $request)
                    <tr class="hover:bg-sky-50/30"><td class="px-5 py-4"><p class="text-sm font-bold text-slate-800">{{ $request->request_number }}</p><p class="mt-0.5 text-xs text-slate-400">{{ $request->request_date?->translatedFormat('d M Y') }}</p></td><td class="px-5 py-4"><p class="text-sm font-semibold text-slate-700">{{ $request->nutritionRequirementPlan?->plan_number ?? '—' }}</p><p class="mt-0.5 max-w-52 truncate text-xs text-slate-400">{{ $request->notes ?: 'Tanpa catatan' }}</p></td><td class="px-5 py-4 text-sm text-slate-600">{{ $request->needed_date?->translatedFormat('d M Y') ?? '—' }}</td><td class="px-5 py-4 text-center text-sm font-bold text-slate-700">{{ $request->total_items }}</td><td class="px-5 py-4 text-right text-sm font-bold text-slate-700">Rp {{ number_format((float) $request->estimated_total_amount, 0, ',', '.') }}</td><td class="px-5 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-[10px] font-bold ring-1 {{ $request->status === \App\Models\ProcurementRequest::STATUS_ORDERED ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : ($request->status === \App\Models\ProcurementRequest::STATUS_REVISION ? 'bg-rose-50 text-rose-700 ring-rose-100' : 'bg-slate-100 text-slate-600 ring-slate-200') }}">{{ $statuses[$request->status] ?? '-' }}</span></td><td class="px-5 py-4 text-right"><a wire:navigate href="{{ route('v3.procurement.show', $request) }}" class="rounded-lg bg-sky-50 px-3 py-2 text-[10px] font-bold text-sky-700 ring-1 ring-sky-100">Buka</a></td></tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-16 text-center"><p class="text-sm font-bold text-slate-700">Belum ada permintaan pembelian</p><p class="mt-1 text-xs text-slate-400">Buat dari rincian Kebutuhan Bahan yang sudah dihitung.</p></td></tr>
                @endforelse
            </tbody></table></div>
            @if ($requests->hasPages())<div class="border-t border-slate-100 px-5 py-4">{{ $requests->links() }}</div>@endif
        </section>
    </div>
</x-v3.shell>
