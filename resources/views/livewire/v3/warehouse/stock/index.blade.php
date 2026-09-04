<x-v3.shell :$unit :$navigation :$roleLabel title="Kartu Stok" eyebrow="Saldo resmi, lot, dan mutasi per satuan">
    <div class="mx-auto max-w-[1450px] space-y-5">
        <section class="rounded-[28px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7">
            <div class="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                <div>
                    <span class="rounded-full bg-cyan-300/10 px-3 py-1 text-[10px] font-bold uppercase tracking-[.16em] text-cyan-200 ring-1 ring-cyan-300/20">Buku besar barang</span>
                    <h2 class="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">Saldo mengikuti satuan asli setiap barang.</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">Penerimaan lolos QC menambah stok. Pengambilan terverifikasi dan penyesuaian mengurangi atau mengoreksi stok dengan referensi yang dapat diaudit.</p>
                    <div class="mt-4 inline-flex rounded-xl bg-white/10 p-1"><button wire:click="$set('warehouseType','food')" class="rounded-lg px-4 py-2 text-xs font-bold {{ $warehouseType === 'food' ? 'bg-cyan-300 text-[#081d3a]' : 'text-white' }}">Pangan</button><button wire:click="$set('warehouseType','non_food')" class="rounded-lg px-4 py-2 text-xs font-bold {{ $warehouseType === 'non_food' ? 'bg-cyan-300 text-[#081d3a]' : 'text-white' }}">Non-Pangan</button></div>
                </div>
                <div class="flex gap-2">
                    @if(auth()->user()->is_super_admin || auth()->user()->can('stock.create'))
                        <a wire:navigate href="{{ route('v3.warehouse.opening-stocks.index', ['gudang' => $warehouseType]) }}" class="inline-flex h-11 items-center rounded-xl bg-cyan-300 px-4 text-xs font-bold text-[#081d3a]">Input Stok Awal</a>
                    @endif
                    <a wire:navigate href="{{ route('v3.warehouse.receipts.index') }}" class="inline-flex h-11 items-center rounded-xl border border-white/15 bg-white/10 px-4 text-xs font-bold text-white">Penerimaan</a>
                    <a wire:navigate href="{{ route('v3.warehouse.withdrawals.index') }}" class="inline-flex h-11 items-center rounded-xl border border-white/15 bg-white/10 px-4 text-xs font-bold text-white">Pengambilan</a>
                </div>
            </div>
            <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-2xl border border-white/10 bg-white/[.07] px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-slate-400">Jenis barang</p><p class="mt-1 text-xl font-bold">{{ $ingredientCount }}</p></div>
                <div class="rounded-2xl border border-cyan-300/20 bg-cyan-300/10 px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-cyan-200/70">Jenis satuan</p><p class="mt-1 text-xl font-bold text-cyan-100">{{ $unitCount }}</p></div>
                <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-emerald-200/70">Lot aktif</p><p class="mt-1 text-xl font-bold text-emerald-100">{{ $activeLotCount }}</p></div>
                <div class="rounded-2xl border border-amber-300/20 bg-amber-300/10 px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-amber-200/70">Menunggu verifikasi</p><p class="mt-1 text-xl font-bold text-amber-100">{{ $pendingCount }}</p></div>
            </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Saldo resmi saat ini</p>
            <h3 class="mt-1 text-lg font-bold text-slate-950">Ringkasan per barang</h3>
            @if($warehouseType === 'food')
                <div class="mt-3 flex flex-wrap gap-3">
                    <input wire:model.live.debounce.350ms="search" type="search" placeholder="Cari nama/kode bahan atau lokasi..." class="h-11 flex-1 rounded-xl border border-slate-200 px-4 text-sm">
                    <a href="{{ route('v3.warehouse.stock.export', ['q' => $search]) }}" class="rounded-xl bg-sky-700 px-4 py-3 text-sm font-bold text-white">Ekspor rekap CSV</a>
                </div>
            @endif
            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @forelse ($balances as $balance)
                    <article class="rounded-2xl border border-slate-200 bg-slate-50/50 p-4">
                        <p class="text-sm font-bold text-slate-800">{{ $balance->ingredient_name_snapshot }}</p>
                        <p class="mt-1 text-[10px] text-slate-400">Mutasi terakhir {{ $balance->last_movement_date ? \Illuminate\Support\Carbon::parse($balance->last_movement_date)->translatedFormat('d M Y') : '—' }}</p>
                        <p class="mt-4 text-2xl font-bold {{ (float) $balance->balance_quantity < 0 ? 'text-rose-700' : 'text-slate-950' }}">{{ $balance->balance_quantity === null ? 'Periksa satuan' : number_format((float) $balance->balance_quantity, 3, ',', '.') }} <span class="text-sm font-semibold text-slate-400">{{ $balance->unit_snapshot }}</span></p>
                        @if($warehouseType === 'food')
                            <p class="mt-2 text-xs text-slate-500">{{ $balance->code }} · {{ $balance->active_lot_count }} lot aktif</p>
                            @if($balance->conversion_warning)<p class="mt-2 text-xs text-amber-700">{{ $balance->conversion_warning }}</p>@endif
                            <button wire:click="$set('ingredientId', {{ $balance->ingredient_id }})" class="mt-3 rounded-lg bg-sky-100 px-3 py-2 text-xs font-bold text-sky-800">Lihat lot dan mutasi</button>
                        @endif
                        <div class="mt-3 flex justify-between border-t border-slate-200 pt-3 text-[10px] text-slate-500"><span>Masuk {{ number_format((float) $balance->total_in, 2, ',', '.') }}</span><span>Keluar {{ number_format((float) $balance->total_out, 2, ',', '.') }}</span></div>
                    </article>
                @empty
                    <div class="col-span-full py-10 text-center text-sm text-slate-500">Belum ada saldo barang.</div>
                @endforelse
            </div>
        </section>

        @if($card)
            @include('livewire.v3.warehouse.stock.detail')
        @endif
        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:p-5">
                <input wire:model.live.debounce.350ms="search" type="search" placeholder="Cari barang, referensi, atau batch..." class="h-11 flex-1 rounded-xl border border-slate-200 bg-slate-50/50 px-4 text-sm">
                <select wire:model.live="type" class="h-11 min-w-52 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-600"><option value="all">Semua jenis mutasi</option>@foreach ($types as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[930px] text-left">
                    <thead><tr class="border-b border-slate-100 bg-slate-50/70 text-[10px] font-bold uppercase tracking-wider text-slate-400"><th class="px-5 py-3.5">Tanggal</th><th class="px-5 py-3.5">Referensi</th><th class="px-5 py-3.5">Barang</th><th class="px-5 py-3.5">Jenis</th><th class="px-5 py-3.5 text-right">Masuk</th><th class="px-5 py-3.5 text-right">Keluar</th><th class="px-5 py-3.5">Lot / expired</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($movements as $movement)
                            <tr><td class="px-5 py-4 text-sm text-slate-600">{{ $movement->movement_date?->translatedFormat('d M Y') }}</td><td class="px-5 py-4 text-sm font-bold text-slate-800">{{ $movement->reference_number ?: '—' }}</td><td class="px-5 py-4"><p class="text-sm font-semibold text-slate-700">{{ $movement->ingredient_name_snapshot }}</p><p class="text-xs text-slate-400">{{ $movement->unit_snapshot }}</p></td><td class="px-5 py-4 text-xs">{{ $types[$movement->movement_type] ?? '-' }}</td><td class="px-5 py-4 text-right text-sm font-bold text-emerald-700">{{ (float) $movement->quantity_in > 0 ? number_format((float) $movement->quantity_in, 3, ',', '.').' '.$movement->unit_snapshot : '—' }}</td><td class="px-5 py-4 text-right text-sm font-bold text-amber-700">{{ (float) $movement->quantity_out > 0 ? number_format((float) $movement->quantity_out, 3, ',', '.').' '.$movement->unit_snapshot : '—' }}</td><td class="px-5 py-4 text-xs text-slate-500">{{ $movement->supplier_batch_number ?: 'Tanpa batch' }}<br>{{ $movement->expired_date?->translatedFormat('d M Y') ?? 'Tanpa expired' }}</td></tr>
                        @empty
                            <tr><td colspan="7" class="px-5 py-16 text-center text-sm text-slate-500">Belum ada mutasi yang sesuai.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($movements->hasPages()) <div class="border-t border-slate-100 px-5 py-4">{{ $movements->links() }}</div> @endif
        </section>
    </div>
</x-v3.shell>
