<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Pengambilan dan Retur Bahan Baku</title>
    <style>
        @page { size: A4 landscape; margin: 22px 28px 30px; }
        body { font-family: DejaVu Sans, sans-serif; color: #12213b; font-size: 9px; }
        .header { width: 100%; border-bottom: 2px solid #0b2a50; padding-bottom: 10px; margin-bottom: 14px; }
        .logo { width: 56px; height: 56px; }
        .heading { padding-left: 10px; }
        .eyebrow { color: #1767a4; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        h1 { margin: 3px 0 4px; font-size: 16px; }
        h2 { margin: 17px 0 7px; font-size: 11px; color: #0b2a50; }
        .muted { color: #53647b; font-size: 8px; }
        table.data { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .data th, .data td { border: 1px solid #8795a9; padding: 6px 5px; vertical-align: top; overflow-wrap: break-word; }
        .data th { background: #e8f1f8; color: #0b2a50; font-weight: bold; text-align: left; }
        .data thead { display: table-header-group; }
        .data tr { page-break-inside: avoid; }
        .number { text-align: right; white-space: nowrap; }
        .center { text-align: center; }
        .note { margin-top: 12px; color: #53647b; font-size: 8px; }
    </style>
</head>
<body>
    @php
        $withdrawalStatuses = [
            \App\Models\WarehouseWithdrawal::DRAFT => 'Draft',
            \App\Models\WarehouseWithdrawal::WAITING => 'Menunggu verifikasi',
            \App\Models\WarehouseWithdrawal::REVISION => 'Perlu revisi',
            \App\Models\WarehouseWithdrawal::VERIFIED => 'Terverifikasi',
            \App\Models\WarehouseWithdrawal::REJECTED => 'Ditolak',
        ];
        $returnStatuses = [
            \App\Models\PreparationReturn::WAITING => 'Menunggu verifikasi',
            \App\Models\PreparationReturn::VERIFIED => 'Terverifikasi',
            \App\Models\PreparationReturn::REJECTED => 'Ditolak',
        ];
    @endphp
    <table class="header"><tr>
        <td style="width: 62px"><img class="logo" src="{{ public_path('images/logo-bgn.png') }}" alt="Logo BGN"></td>
        <td class="heading">
            <div class="eyebrow">{{ $unit->name }}</div>
            <h1>Laporan Pengambilan dan Retur Bahan Baku</h1>
            <div class="muted">Gudang Pangan - {{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('d F Y') }}</div>
        </td>
    </tr></table>

    <h2>Pengambilan Bahan - {{ $withdrawals->count() }} dokumen</h2>
    <table class="data">
        <thead><tr>
            <th style="width: 12%">No. pengambilan</th>
            <th style="width: 10%">Divisi</th>
            <th style="width: 13%">Pengambil</th>
            <th style="width: 17%">Bahan baku</th>
            <th style="width: 10%">Lot</th>
            <th style="width: 11%">Diminta</th>
            <th style="width: 11%">Aktual</th>
            <th style="width: 16%">Status / catatan</th>
        </tr></thead>
        <tbody>
            @forelse ($withdrawals as $withdrawal)
                @forelse ($withdrawal->items as $item)
                    <tr>
                        <td>{{ $withdrawal->withdrawal_number }}</td>
                        <td>{{ ucfirst($withdrawal->division_code) }}</td>
                        <td>{{ $withdrawal->taker?->name ?? '-' }}</td>
                        <td>{{ $item->ingredient_name_snapshot ?: ($item->ingredient?->name ?? '-') }}</td>
                        <td>{{ $item->lot_number_snapshot ?: '-' }}</td>
                        <td class="number">{{ number_format((float) ($item->requested_quantity ?? $item->taken_quantity_kg), 3, ',', '.') }} {{ $item->unit_snapshot }}</td>
                        <td class="number">{{ $item->actual_quantity === null ? '-' : number_format((float) $item->actual_quantity, 3, ',', '.').' '.$item->unit_snapshot }}</td>
                        <td>{{ $withdrawalStatuses[$withdrawal->status] ?? $withdrawal->status }}@if ($withdrawal->verifier)<br>Gudang: {{ $withdrawal->verifier->name }}@endif @if ($withdrawal->decision_notes)<br>{{ $withdrawal->decision_notes }}@endif</td>
                    </tr>
                @empty
                    <tr><td>{{ $withdrawal->withdrawal_number }}</td><td>{{ ucfirst($withdrawal->division_code) }}</td><td>{{ $withdrawal->taker?->name ?? '-' }}</td><td colspan="4">Belum ada bahan tercatat</td><td>{{ $withdrawalStatuses[$withdrawal->status] ?? $withdrawal->status }}</td></tr>
                @endforelse
            @empty
                <tr><td colspan="8" class="center">Belum ada pengambilan bahan baku pada tanggal ini.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Retur dari Divisi - {{ $returns->count() }} item</h2>
    <table class="data">
        <thead><tr>
            <th style="width: 12%">No. retur</th>
            <th style="width: 10%">Divisi</th>
            <th style="width: 13%">Pengembali</th>
            <th style="width: 17%">Bahan baku</th>
            <th style="width: 11%">Diajukan</th>
            <th style="width: 11%">Aktual</th>
            <th style="width: 10%">Keputusan stok</th>
            <th style="width: 16%">Status / alasan</th>
        </tr></thead>
        <tbody>
            @forelse ($returns as $entry)
                @php($return = $entry['record'])
                <tr>
                    <td>{{ $return->return_number }}</td>
                    <td>{{ $entry['division'] }}</td>
                    <td>{{ $return->returner?->name ?? '-' }}</td>
                    <td>{{ $return->ingredient_name_snapshot }}</td>
                    <td class="number">{{ number_format((float) $return->requested_quantity, 3, ',', '.') }} {{ $return->unit_snapshot }}</td>
                    <td class="number">{{ $return->actual_quantity === null ? '-' : number_format((float) $return->actual_quantity, 3, ',', '.').' '.$return->unit_snapshot }}</td>
                    <td>{{ match ($return->warehouse_disposition) { 'available' => 'Tersedia', 'quarantine' => 'Karantina', 'rejected' => 'Ditolak', default => '-' } }}</td>
                    <td>{{ $returnStatuses[$return->status] ?? $return->status }}@if ($return->verifier)<br>Gudang: {{ $return->verifier->name }}@endif @if ($return->reason)<br>{{ $return->reason }}@endif</td>
                </tr>
            @empty
                <tr><td colspan="8" class="center">Belum ada retur bahan baku pada tanggal ini.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="note">Jumlah aktual dan keputusan stok baru final setelah verifikasi Gudang. Status menunggu, revisi, atau ditolak tidak dianggap sebagai mutasi stok final.</div>
</body>
</html>
