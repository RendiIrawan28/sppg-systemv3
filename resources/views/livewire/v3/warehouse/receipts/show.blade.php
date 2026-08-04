<x-v3.shell :$unit :$navigation :$roleLabel title="Rincian Penerimaan" eyebrow="Kuantitas, batch, dan QC">
    <div class="mx-auto max-w-[1450px] space-y-5">
        <div class="flex flex-col justify-between gap-3 lg:flex-row lg:items-center"><div><a wire:navigate href="{{ route('v3.warehouse.receipts.index') }}" class="inline-flex items-center gap-2 text-xs font-bold text-sky-700"><x-v3.icon name="arrow-left" class="size-4" /> Kembali ke penerimaan</a><h2 class="mt-3 text-2xl font-bold tracking-tight text-slate-950">{{ $receipt->receipt_number }}</h2><p class="mt-1 text-sm text-slate-500">{{ $receipt->supplier?->name ?? 'Supplier belum ditentukan' }} · {{ $receipt->procurementRequest?->request_number ?? 'Penerimaan manual' }} · {{ $receipt->receipt_date?->translatedFormat('d M Y') }}</p></div><div class="flex flex-wrap gap-2">@if ($receipt->procurementRequest)<a wire:navigate href="{{ route('v3.procurement.show', $receipt->procurementRequest) }}" class="inline-flex h-10 items-center rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-600">Buka permintaan</a>@endif</div></div>
        @if ($actionMessage)<div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-700">{{ $actionMessage }}</div>@endif @error('action')<div class="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{{ $message }}</div>@enderror

        <section class="rounded-[26px] bg-[#081d3a] p-6 text-white shadow-xl sm:p-7"><div class="flex flex-col justify-between gap-5 lg:flex-row lg:items-end"><div><span class="rounded-full bg-emerald-300/10 px-3 py-1 text-[10px] font-bold text-emerald-200 ring-1 ring-emerald-300/20">{{ $statuses[$receipt->status] ?? '-' }}</span><p class="mt-4 text-xs font-bold uppercase tracking-[.14em] text-cyan-200">Kiriman {{ $receipt->supplier?->name ?? 'Supplier' }}</p><h3 class="mt-1 text-2xl font-bold">{{ $receipt->items->count() }} item diterima bersama</h3><p class="mt-2 text-sm text-slate-300">{{ $receipt->notes ?: 'Catat jumlah barang dan unggah satu foto untuk keseluruhan kiriman supplier.' }}</p></div><div class="space-y-3">@foreach($receiptTotalsByUnit as $total)<div><p class="mb-1 text-right text-[10px] font-bold uppercase tracking-wider text-cyan-200">Satuan {{ $total['unit'] }}</p><div class="grid grid-cols-3 gap-3"><div class="rounded-xl border border-white/10 bg-white/[.07] p-3"><p class="text-[10px] text-slate-400">Dipesan</p><p class="mt-1 text-lg font-bold">{{ number_format($total['ordered'], 3, ',', '.') }} {{ $total['unit'] }}</p></div><div class="rounded-xl border border-white/10 bg-white/[.07] p-3"><p class="text-[10px] text-slate-400">Diterima baik</p><p class="mt-1 text-lg font-bold">{{ number_format($total['accepted'], 3, ',', '.') }} {{ $total['unit'] }}</p></div><div class="rounded-xl border border-rose-300/20 bg-rose-300/10 p-3"><p class="text-[10px] text-rose-200/70">Ditolak</p><p class="mt-1 text-lg font-bold text-rose-100">{{ number_format($total['rejected'], 3, ',', '.') }} {{ $total['unit'] }}</p></div></div></div>@endforeach</div></div></section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <label>
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600">Tanggal terima</span>
                    <input wire:model="receiptDate" type="date" @disabled(! $canEdit) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm disabled:opacity-60">
                </label>
                <div>
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600">Petugas penerima</span>
                    <div class="flex h-11 items-center rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm font-semibold text-slate-700">{{ $receipt->received_by_name ?: auth()->user()->name }}</div>
                </div>
                <label>
                    <span class="mb-1.5 block text-xs font-semibold text-slate-600">Catatan penerimaan</span>
                    <input wire:model="notes" type="text" @disabled(! $canEdit) class="h-11 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm disabled:opacity-60">
                </label>
                <div>
                    <label>
                        <span class="mb-1.5 block text-xs font-semibold text-slate-600">Foto kiriman supplier <span class="text-rose-500">*</span></span>
                        @if ($canEdit)
                            <input wire:model="documentation" type="file" accept="image/*" capture="environment" class="block h-11 w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs file:mr-2 file:rounded-lg file:border-0 file:bg-sky-50 file:px-3 file:py-1 file:font-bold file:text-sky-700">
                        @endif
                    </label>
                    <div wire:loading wire:target="documentation" class="mt-1 text-[10px] font-semibold text-sky-600">Mengunggah foto...</div>
                    @error('documentation')<p class="mt-1 text-xs text-rose-600">{{ $message }}</p>@enderror
                    @if ($documentation)
                        <p class="mt-1 text-xs font-semibold text-emerald-600">Foto baru siap disimpan.</p>
                    @elseif ($receipt->documentation_path)
                        <x-v3.documentation-button
                            :url="Storage::disk('public')->url($receipt->documentation_path)"
                            :title="($receipt->supplier?->name ?? 'Supplier').' · '.$receipt->receipt_number"
                            label="Lihat dokumentasi tersimpan"
                            class="mt-1"
                        />
                    @else
                        <p class="mt-1 text-[10px] text-slate-400">Satu foto untuk seluruh barang dari supplier ini.</p>
                    @endif
                </div>
            </div>
        </section>

        <section class="space-y-4">
            @foreach ($receipt->items as $item)
                <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><div class="flex flex-col justify-between gap-2 border-b border-slate-100 pb-4 sm:flex-row sm:items-center"><div><h3 class="text-base font-bold text-slate-950">{{ $item->ingredient_name_snapshot }}</h3><p class="mt-1 text-xs text-slate-400">Dipesan {{ number_format((float) $item->ordered_quantity, 4, ',', '.') }} {{ $item->unit_snapshot }} · {{ $item->supplier?->name ?? 'Supplier tidak tersedia' }}</p></div><span class="w-fit rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-600 ring-1 ring-slate-200">{{ ['pending' => 'Belum dicek', 'accepted' => 'Layak', 'partial' => 'Sebagian ditolak', 'rejected' => 'Ditolak'][$item->quality_status] ?? '-' }}</span></div>
                    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4"><label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Jumlah diterima ({{ $item->unit_snapshot }})</span><input wire:model="rows.{{ $item->id }}.received_quantity" type="number" min="0" step="0.0001" @disabled(! $canEdit) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></label><label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Jumlah baik ({{ $item->unit_snapshot }})</span><input wire:model="rows.{{ $item->id }}.accepted_quantity" type="number" min="0" step="0.0001" @disabled(! $canEdit) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></label><label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Jumlah ditolak ({{ $item->unit_snapshot }})</span><input wire:model="rows.{{ $item->id }}.rejected_quantity" type="number" min="0" step="0.0001" @disabled(! $canEdit) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm"></label><label><span class="mb-1 block text-[11px] font-semibold text-slate-500">Catatan barang</span><input wire:model="rows.{{ $item->id }}.quality_notes" type="text" @disabled(! $canEdit) class="h-10 w-full rounded-xl border border-slate-200 bg-white px-3 text-sm" placeholder="Opsional"></label></div>
                </article>
            @endforeach
        </section>

        @if ($canEdit)<div class="sticky bottom-4 z-10 flex flex-wrap justify-end gap-2 rounded-2xl border border-slate-200 bg-white/90 p-3 shadow-xl backdrop-blur"><button wire:click="delete" wire:confirm="Hapus draft penerimaan supplier ini?" class="h-10 rounded-xl px-4 text-xs font-bold text-rose-700 hover:bg-rose-50">Hapus draft</button><button wire:click="save" class="h-10 rounded-xl border border-slate-200 px-4 text-xs font-bold text-slate-700">Simpan penerimaan</button>@if (auth()->user()->is_super_admin || auth()->user()->can('stock.create'))<button wire:click="receive" wire:confirm="Kunci penerimaan supplier ini dan masukkan barang baik ke stok?" class="h-10 rounded-xl bg-emerald-600 px-4 text-xs font-bold text-white">Terima & masukkan stok</button>@endif</div>@endif

    </div>
</x-v3.shell>
