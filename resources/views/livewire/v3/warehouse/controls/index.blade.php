<x-v3.shell :$unit :$navigation :$roleLabel title="Kontrol Stok" eyebrow="Lot, karantina, pengembalian, dan stock opname">
<div class="mx-auto max-w-[1450px] space-y-5">
@if(session('v3.status'))<div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-700">{{ session('v3.status') }}</div>@endif
<section class="rounded-[28px] bg-[#081d3a] p-6 text-white"><h2 class="text-2xl font-bold">Satu halaman untuk kontrol stok.</h2><p class="mt-2 text-sm text-slate-300">Lot karantina tidak dapat diambil divisi. Stock opname dan pengembalian selalu membentuk mutasi yang dapat diaudit.</p></section>
<section class="space-y-3">
    <div><h3 class="font-bold">Retur dari Divisi</h3><p class="text-xs text-slate-500">Saldo dan kartu stok belum berubah sampai retur diverifikasi Gudang.</p></div>
    @forelse($returns as $return)
        <div class="rounded-2xl border {{ $return->status === \App\Models\PreparationReturn::WAITING ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white' }} p-4">
            <div class="flex flex-wrap justify-between gap-3">
                <div><span class="rounded bg-sky-100 px-2 py-1 text-[10px] font-bold text-sky-700">Persiapan</span> <b>{{ $return->ingredient_name_snapshot }}</b> · {{ $return->return_number }}<br><span class="text-xs text-slate-600">Diajukan {{ number_format((float) $return->requested_quantity, 3, ',', '.') }} {{ $return->unit_snapshot }} oleh {{ $return->returner?->name ?: '-' }} · kondisi {{ str($return->condition_status)->title() }}</span><br><span class="text-xs">{{ $return->reason }}</span></div>
                <div class="text-right text-xs">@if($return->photo_path)<x-v3.documentation-button :url="Storage::disk('public')->url($return->photo_path)" :title="'Bukti retur · '.$return->ingredient_name_snapshot" label="Lihat bukti" class="mb-1" /><br>@endif<b>{{ str($return->status)->replace('_', ' ')->title() }}</b></div>
            </div>
            @if($return->status === \App\Models\PreparationReturn::WAITING && $canApprove)
                <div class="mt-3 grid gap-2 md:grid-cols-4">
                    <input wire:model="returnActualQuantities.{{ $return->id }}" type="number" min="0" step=".001" class="h-10 rounded-lg border px-3 text-sm" placeholder="Jumlah aktual">
                    <select wire:model="returnDispositions.{{ $return->id }}" class="h-10 rounded-lg border px-3 text-sm"><option value="available">Kembali tersedia</option><option value="quarantine">Karantina</option><option value="rejected">Ditolak/tidak tersedia</option></select>
                    <input wire:model="returnNotes.{{ $return->id }}" class="h-10 rounded-lg border px-3 text-sm" placeholder="Catatan Gudang">
                    <div class="flex gap-2"><button wire:click="rejectReturn({{ $return->id }})" class="flex-1 rounded-lg border border-rose-200 px-3 text-xs font-bold text-rose-700">Tolak</button><button wire:click="verifyReturn({{ $return->id }})" class="flex-1 rounded-lg bg-emerald-600 px-3 text-xs font-bold text-white">Verifikasi</button></div>
                </div>
            @elseif($return->status === \App\Models\PreparationReturn::VERIFIED)
                <p class="mt-3 text-xs text-emerald-700">Diterima aktual {{ number_format((float) $return->actual_quantity, 3, ',', '.') }} {{ $return->unit_snapshot }} · keputusan {{ str($return->warehouse_disposition)->title() }} · {{ $return->warehouse_notes ?: 'tanpa catatan tambahan' }}</p>
            @elseif($return->warehouse_notes)
                <p class="mt-3 text-xs text-rose-700">Alasan Gudang: {{ $return->warehouse_notes }}</p>
            @endif
        </div>
    @empty
    @endforelse

    @foreach($processingReturns as $return)
        <div class="rounded-2xl border {{ $return->status === \App\Models\ProcessingReturn::WAITING ? 'border-amber-200 bg-amber-50' : 'border-slate-200 bg-white' }} p-4">
            <div class="flex flex-wrap justify-between gap-3">
                <div><span class="rounded bg-violet-100 px-2 py-1 text-[10px] font-bold text-violet-700">Pengolahan</span> <b>{{ $return->ingredient_name_snapshot }}</b> · {{ $return->return_number }}<br><span class="text-xs text-slate-600">Diajukan {{ number_format((float) $return->requested_quantity, 3, ',', '.') }} {{ $return->unit_snapshot }} oleh {{ $return->returner?->name ?: '-' }}</span><br><span class="text-xs">{{ $return->reason }}</span></div>
                <div class="text-right text-xs"><b>{{ str($return->status)->replace('_', ' ')->title() }}</b></div>
            </div>
            @if($return->status === \App\Models\ProcessingReturn::WAITING && $canApprove)
                <div class="mt-3 grid gap-2 md:grid-cols-4">
                    <input wire:model="processingReturnActualQuantities.{{ $return->id }}" type="number" min="0" step=".001" class="h-10 rounded-lg border px-3 text-sm" placeholder="Jumlah aktual">
                    <select wire:model="processingReturnDispositions.{{ $return->id }}" class="h-10 rounded-lg border px-3 text-sm"><option value="available">Kembali tersedia</option><option value="quarantine">Karantina</option><option value="rejected">Ditolak/tidak tersedia</option></select>
                    <input wire:model="processingReturnNotes.{{ $return->id }}" class="h-10 rounded-lg border px-3 text-sm" placeholder="Catatan Gudang">
                    <div class="flex gap-2"><button wire:click="rejectProcessingReturn({{ $return->id }})" class="flex-1 rounded-lg border border-rose-200 px-3 text-xs font-bold text-rose-700">Tolak</button><button wire:click="verifyProcessingReturn({{ $return->id }})" class="flex-1 rounded-lg bg-emerald-600 px-3 text-xs font-bold text-white">Verifikasi</button></div>
                </div>
            @elseif($return->status === \App\Models\ProcessingReturn::VERIFIED)
                <p class="mt-3 text-xs text-emerald-700">Diterima aktual {{ number_format((float) $return->actual_quantity, 3, ',', '.') }} {{ $return->unit_snapshot }} · keputusan {{ str($return->warehouse_disposition)->title() }} · {{ $return->warehouse_notes ?: 'tanpa catatan tambahan' }}</p>
            @elseif($return->warehouse_notes)
                <p class="mt-3 text-xs text-rose-700">Alasan Gudang: {{ $return->warehouse_notes }}</p>
            @endif
        </div>
    @endforeach

    @if($returns->isEmpty() && $processingReturns->isEmpty())
        <div class="rounded-2xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">Belum ada retur dari divisi.</div>
    @endif
</section>
@if($canEdit)<form wire:submit="createAdjustment" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm md:grid-cols-4"><select wire:model="lotId" class="h-11 rounded-xl border border-slate-200 px-3 text-sm"><option value="">Pilih lot</option>@foreach($lots as $lot)<option value="{{ $lot->id }}">{{ $lot->ingredient->name }} · {{ $lot->lot_number }} · {{ number_format((float)$lot->balance_quantity,3,',','.') }} {{ $lot->unit_snapshot }}</option>@endforeach</select><select wire:model="type" class="h-11 rounded-xl border border-slate-200 px-3 text-sm"><option value="stock_opname">Stock opname</option><option value="return_from_division">Pengembalian divisi</option><option value="damage">Rusak/susut</option></select><input wire:model="actualQuantity" type="number" step="0.001" min="0" class="h-11 rounded-xl border border-slate-200 px-3 text-sm" placeholder="Saldo aktual setelah penyesuaian"><button class="h-11 rounded-xl bg-sky-600 text-xs font-bold text-white">Catat penyesuaian</button><textarea wire:model="reason" rows="2" class="md:col-span-4 rounded-xl border border-slate-200 p-3 text-sm" placeholder="Alasan wajib"></textarea></form>@endif
<section class="overflow-x-auto rounded-2xl border border-slate-200 bg-white"><table class="w-full min-w-[1100px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-4">Bahan / lot</th><th class="p-4">Expired</th><th class="p-4">Saldo</th><th class="p-4">Lokasi</th><th class="p-4">Penyimpanan</th><th class="p-4">Status</th><th class="p-4"></th></tr></thead><tbody class="divide-y">@foreach($lots as $lot)<tr><td class="p-4"><b>{{ $lot->ingredient->name }}</b><br><span class="text-xs text-slate-500">{{ $lot->lot_number ?: '-' }}</span></td><td class="p-4">{{ $lot->expired_date?->format('d-m-Y') ?? '-' }}</td><td class="p-4 font-bold">{{ number_format((float)$lot->balance_quantity,3,',','.') }} {{ $lot->unit_snapshot }}</td><td class="p-4"><input wire:model="locations.{{ $lot->id }}" @disabled(!$canEdit) class="h-9 rounded-lg border border-slate-200 px-2"></td><td class="p-4"><select wire:model="storageTypes.{{ $lot->id }}" @disabled(!$canEdit) class="h-9 rounded-lg border border-slate-200 px-2"><option value="dry">Gudang kering</option><option value="wet">Gudang basah</option><option value="freezer">Freezer</option><option value="chiller">Chiller</option></select></td><td class="p-4"><select wire:model="statuses.{{ $lot->id }}" @disabled(!$canEdit) class="h-9 rounded-lg border border-slate-200 px-2"><option value="available">Tersedia</option><option value="quarantine">Karantina</option><option value="rejected">Ditolak</option><option value="depleted">Habis</option></select></td><td class="p-4">@if($canEdit)<button wire:click="saveLot({{ $lot->id }})" class="text-xs font-bold text-sky-700">Simpan</button>@endif</td></tr>@endforeach</tbody></table></section>
<section class="space-y-2"><h3 class="font-bold">Penyesuaian terakhir</h3>@foreach($adjustments as $a)<div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm"><div><b>{{ $a->lot?->ingredient?->name }}</b> · {{ str($a->type)->replace('_',' ')->title() }}<br><span class="text-xs text-slate-500">{{ $a->system_quantity }} → {{ $a->actual_quantity }} {{ $a->unit_snapshot }} · {{ $a->reason }}</span></div><div>@if($a->status===\App\Models\StockAdjustment::DRAFT && $canApprove)<button wire:click="verifyAdjustment({{ $a->id }})" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white">Verifikasi</button>@else<span class="text-xs font-bold text-slate-500">{{ ucfirst($a->status) }}</span>@endif</div></div>@endforeach</section>
</div></x-v3.shell>
