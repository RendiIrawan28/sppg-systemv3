<x-v3.shell :$unit :$navigation :$roleLabel title="Penerimaan Bahan" eyebrow="Pemeriksaan kuantitas dan mutu">
    <div class="mx-auto max-w-[1450px] space-y-5">
        <x-v3.flash-alert />
        <x-v3.date-filter label="Tanggal penerimaan" />

        <div class="inline-flex rounded-2xl border border-slate-200 bg-white p-1 shadow-sm">
            <button wire:click="$set('warehouseType', 'food')" class="rounded-xl px-4 py-2 text-xs font-bold {{ $warehouseType === 'food' ? 'bg-[#081d3a] text-white' : 'text-slate-500' }}">Pangan</button>
            <button wire:click="$set('warehouseType', 'non_food')" class="rounded-xl px-4 py-2 text-xs font-bold {{ $warehouseType === 'non_food' ? 'bg-[#081d3a] text-white' : 'text-slate-500' }}">Non-Pangan</button>
        </div>

        <section class="rounded-[28px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7">
            <div class="flex flex-col justify-between gap-5 lg:flex-row lg:items-end">
                <div>
                    <span class="rounded-full bg-emerald-300/10 px-3 py-1 text-[10px] font-bold uppercase tracking-[.16em] text-emerald-200 ring-1 ring-emerald-300/20">Gerbang stok</span>
                    <h2 class="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">Terima barang, lakukan QC, lalu masukkan stok.</h2>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-300">Pilih sumber penerimaan. Hanya barang yang lolos QC yang masuk ke kartu stok.</p>
                </div>
                @if (auth()->user()->is_super_admin || auth()->user()->can('procurement.view'))
                    <a wire:navigate href="{{ route('v3.procurement.index') }}" class="inline-flex h-11 items-center rounded-xl border border-white/15 bg-white/10 px-4 text-xs font-bold text-white">Lihat pengadaan</a>
                @endif
            </div>
            <div class="mt-6 grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-white/10 bg-white/[.07] px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-slate-400">Dokumen tanggal ini</p><p class="mt-1 text-xl font-bold">{{ $receiptCount }}</p></div>
                <div class="rounded-2xl border border-amber-300/20 bg-amber-300/10 px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-amber-200/70">Menunggu QC</p><p class="mt-1 text-xl font-bold text-amber-100">{{ $draftCount }}</p></div>
                <div class="rounded-2xl border border-emerald-300/20 bg-emerald-300/10 px-4 py-3"><p class="text-[10px] uppercase tracking-wider text-emerald-200/70">Masuk stok</p><p class="mt-1 text-xl font-bold text-emerald-100">{{ $receivedCount }}</p></div>
            </div>
        </section>

        @if (auth()->user()->is_super_admin || auth()->user()->can('stock.create'))
            <div class="grid gap-4 lg:grid-cols-2">
                <form wire:submit="createReceipt" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Opsi 1 · Dari pengadaan</p>
                    <h3 class="mt-1 text-base font-bold text-slate-950">Pilih pengadaan yang sudah dipesan</h3>
                    <p class="mt-2 text-xs leading-5 text-slate-500">Barang dan jumlah terisi dari pengadaan. Dokumen penerimaan dibuat per supplier; petugas tetap memeriksa fisik, QC, dan mengunggah foto setiap barang.</p>
                    <label class="mt-4 block">
                        <span class="mb-1.5 block text-xs font-semibold text-slate-600">Cari nomor pengadaan</span>
                        <input wire:model.live.debounce.350ms="procurementSearch" type="search" placeholder="Contoh: PB/2026/0001" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    </label>
                    <label class="mt-3 block">
                        <span class="mb-1.5 block text-xs font-semibold text-slate-600">Pengadaan tersedia</span>
                        <select wire:model="procurementId" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                            <option value="">Pilih pengadaan</option>
                            @foreach ($orderedRequests as $request)
                                <option value="{{ $request->id }}">{{ $request->request_number }} · {{ $request->needed_date?->format('d-m-Y') }}{{ $request->stock_receipts_count ? ' · penerimaan sudah ada' : '' }}</option>
                            @endforeach
                        </select>
                        @error('procurementId')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                    </label>
                    <label class="mt-3 block">
                        <span class="mb-1.5 block text-xs font-semibold text-slate-600">Tanggal penerimaan baru</span>
                        <input wire:model="newReceiptDate" type="date" max="{{ today()->toDateString() }}" class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm">
                        @error('newReceiptDate')<span class="mt-1 block text-xs text-rose-600">{{ $message }}</span>@enderror
                    </label>
                    <button type="submit" @disabled($orderedRequests->isEmpty()) class="mt-4 h-11 w-full rounded-xl bg-[#081d3a] px-4 text-xs font-bold text-white disabled:cursor-not-allowed disabled:opacity-50">Buat atau buka penerimaan per supplier</button>
                    @if ($orderedRequests->isEmpty())
                        <p class="mt-3 text-xs text-slate-500">Belum ada pengadaan berstatus dipesan{{ $procurementSearch !== '' ? ' dengan nomor tersebut' : '' }}.</p>
                    @endif
                </form>
                <section class="flex flex-col rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                    <p class="text-[10px] font-bold uppercase tracking-[.16em] text-sky-700">Opsi 2 · Input manual</p>
                    <h3 class="mt-1 text-base font-bold text-slate-950">Terima langsung dari supplier</h3>
                    <p class="mt-2 flex-1 text-xs leading-5 text-slate-500">Untuk barang yang belum memiliki pengadaan. Pilih supplier dan barang, catat jumlah hasil QC, lalu unggah foto masing-masing barang.</p>
                    <a wire:navigate href="{{ route('v3.warehouse.receipts.manual', ['gudang' => $warehouseType]) }}" class="mt-4 inline-flex h-11 items-center justify-center rounded-xl bg-sky-600 px-4 text-xs font-bold text-white">+ Buat penerimaan manual</a>
                </section>
            </div>
        @else
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm font-bold text-slate-800">Akses baca-saja</p>
                <p class="mt-1 text-xs leading-5 text-slate-500">Anda dapat melihat penerimaan dan kartu stok sesuai hak akses.</p>
            </section>
        @endif

        @if ($procurementFilter !== '')
            <div class="flex items-center justify-between gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-xs font-semibold text-sky-800">
                <span>Menampilkan seluruh penerimaan dari pengadaan yang dipilih, termasuk jika tanggalnya berbeda.</span>
                <button wire:click="$set('procurementFilter', '')" class="font-bold underline">Tampilkan semua</button>
            </div>
        @endif

        <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:p-5">
                <div class="relative flex-1">
                    <span class="pointer-events-none absolute inset-y-0 left-0 grid w-11 place-items-center text-slate-400"><x-v3.icon name="search" class="size-[18px]" /></span>
                    <input wire:model.live.debounce.350ms="search" type="search" placeholder="Cari nomor, pengadaan, supplier, atau bahan..." class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50/50 pl-11 pr-4 text-sm">
                </div>
                <select wire:model.live="status" class="h-11 min-w-52 rounded-xl border border-slate-200 bg-white px-3 text-sm text-slate-600">
                    <option value="all">Semua status</option>
                    @foreach ($statuses as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                </select>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full min-w-[820px] text-left">
                    <thead><tr class="border-b border-slate-100 bg-slate-50/70 text-[10px] font-bold uppercase tracking-wider text-slate-400"><th class="px-5 py-3.5">Penerimaan</th><th class="px-5 py-3.5">Supplier</th><th class="px-5 py-3.5">Referensi</th><th class="px-5 py-3.5 text-center">Item</th><th class="px-5 py-3.5">Status</th><th class="px-5 py-3.5 text-right">Aksi</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($receipts as $receipt)
                            <tr class="hover:bg-sky-50/30">
                                <td class="px-5 py-4"><p class="text-sm font-bold text-slate-800">{{ $receipt->receipt_number }}</p><p class="mt-0.5 text-xs text-slate-400">{{ $receipt->receipt_date?->translatedFormat('d M Y') }}</p></td>
                                <td class="px-5 py-4 text-sm font-bold text-slate-700">{{ $receipt->supplier?->name ?? 'Belum ditentukan' }}</td>
                                <td class="px-5 py-4 text-sm font-semibold text-slate-600">{{ $receipt->procurementRequest?->request_number ?? 'Manual' }}</td>
                                <td class="px-5 py-4 text-center text-sm font-bold text-slate-700">{{ $receipt->items->count() }}</td>
                                <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-[10px] font-bold ring-1 {{ $receipt->status === \App\Models\StockReceipt::STATUS_RECEIVED ? 'bg-emerald-50 text-emerald-700 ring-emerald-100' : 'bg-slate-100 text-slate-600 ring-slate-200' }}">{{ $statuses[$receipt->status] ?? '-' }}</span></td>
                                <td class="px-5 py-4 text-right"><a wire:navigate href="{{ route('v3.warehouse.receipts.show', $receipt) }}" class="rounded-lg bg-sky-50 px-3 py-2 text-[10px] font-bold text-sky-700 ring-1 ring-sky-100">Buka</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-5 py-16 text-center"><p class="text-sm font-bold text-slate-700">Belum ada penerimaan pada tanggal ini</p><p class="mt-1 text-xs text-slate-400">Ubah tanggal untuk melihat riwayat, atau buat penerimaan dari pengadaan maupun manual.</p></td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($receipts->hasPages())<div class="border-t border-slate-100 px-5 py-4">{{ $receipts->links() }}</div>@endif
        </section>
    </div>
</x-v3.shell>
