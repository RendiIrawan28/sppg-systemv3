<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Penerimaan Bahan Baku</title>
    <style>
        @page { size: A4 landscape; margin: 22px 28px 30px; }
        body { font-family: DejaVu Sans, sans-serif; color: #12213b; font-size: 9px; }
        .header { width: 100%; border-bottom: 2px solid #0b2a50; padding-bottom: 10px; margin-bottom: 14px; }
        .logo { width: 56px; height: 56px; }
        .heading { padding-left: 10px; }
        .eyebrow { color: #1767a4; font-size: 9px; font-weight: bold; text-transform: uppercase; }
        h1 { margin: 3px 0 4px; font-size: 16px; }
        .muted { color: #53647b; font-size: 8px; }
        .meta { margin-bottom: 12px; }
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
    <table class="header"><tr>
        <td style="width: 62px"><img class="logo" src="{{ public_path('images/logo-bgn.png') }}" alt="Logo BGN"></td>
        <td class="heading">
            <div class="eyebrow">{{ $unit->name }}</div>
            <h1>Laporan Penerimaan Bahan Baku</h1>
            <div class="muted">Gudang Pangan - {{ \Illuminate\Support\Carbon::parse($date)->translatedFormat('d F Y') }}</div>
        </td>
    </tr></table>

    <div class="meta">Jumlah dokumen: <b>{{ $receipts->count() }}</b> &nbsp; | &nbsp; Jumlah baris bahan: <b>{{ $receipts->sum(fn ($receipt) => $receipt->items->count()) }}</b></div>
    <table class="data">
        <thead><tr>
            <th style="width: 11%">No. penerimaan</th>
            <th style="width: 10%">Supplier</th>
            <th style="width: 10%">Sumber</th>
            <th style="width: 17%">Bahan baku</th>
            <th style="width: 10%">Lot supplier</th>
            <th style="width: 9%">Diterima</th>
            <th style="width: 9%">Lolos QC</th>
            <th style="width: 9%">Ditolak</th>
            <th style="width: 15%">Status / catatan</th>
        </tr></thead>
        <tbody>
            @forelse ($receipts as $receipt)
                @forelse ($receipt->items as $item)
                    <tr>
                        <td>{{ $receipt->receipt_number }}</td>
                        <td>{{ $receipt->supplier?->name ?? '-' }}</td>
                        <td>{{ $receipt->procurementRequest?->request_number ?? 'Manual' }}</td>
                        <td>{{ $item->ingredient_name_snapshot ?: ($item->ingredient?->name ?? '-') }}</td>
                        <td>{{ $item->supplier_batch_number ?: '-' }}</td>
                        <td class="number">{{ number_format((float) $item->received_quantity, 3, ',', '.') }} {{ $item->unit_snapshot }}</td>
                        <td class="number">{{ number_format((float) $item->accepted_quantity, 3, ',', '.') }} {{ $item->unit_snapshot }}</td>
                        <td class="number">{{ number_format((float) $item->rejected_quantity, 3, ',', '.') }} {{ $item->unit_snapshot }}</td>
                        <td>{{ $receipt->status === \App\Models\StockReceipt::STATUS_RECEIVED ? 'Diterima Gudang' : 'Draft - belum masuk stok' }}@if ($receipt->received_by_name)<br>Petugas: {{ $receipt->received_by_name }}@endif @if ($item->quality_notes)<br>{{ $item->quality_notes }}@endif</td>
                    </tr>
                @empty
                    <tr><td>{{ $receipt->receipt_number }}</td><td>{{ $receipt->supplier?->name ?? '-' }}</td><td>{{ $receipt->procurementRequest?->request_number ?? 'Manual' }}</td><td colspan="5">Belum ada bahan tercatat</td><td>{{ $receipt->status === \App\Models\StockReceipt::STATUS_RECEIVED ? 'Diterima Gudang' : 'Draft - belum masuk stok' }}</td></tr>
                @endforelse
            @empty
                <tr><td colspan="9" class="center">Belum ada penerimaan bahan baku pada tanggal ini.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="note">Jumlah ditampilkan per satuan asli bahan; satuan berbeda tidak dijumlahkan. Dokumen berstatus draft belum menambah stok.</div>
</body>
</html>
