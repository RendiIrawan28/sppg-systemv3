<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Pengadaan Bahan {{ $procurement->request_number }}</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111827; }
        h1 { margin: 0; text-align: center; font-size: 17px; }
        h2 { margin: 4px 0 15px; text-align: center; font-size: 11px; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; }
        .meta { margin-bottom: 12px; }
        .meta td { padding: 3px 5px; vertical-align: top; }
        .meta .label { width: 120px; font-weight: bold; }
        .items th, .items td { border: 1px solid #9ca3af; padding: 5px 4px; vertical-align: top; }
        .items th { background: #e5e7eb; text-align: center; font-size: 8px; }
        .center { text-align: center; }
        .right { text-align: right; }
        .total td { background: #f3f4f6; font-weight: bold; }
        .signatures { margin-top: 28px; text-align: center; }
        .signatures td { width: 33.33%; vertical-align: top; }
        .space { height: 52px; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <h1>DAFTAR PENGADAAN BAHAN</h1>
    <h2>{{ $procurement->sppgUnit?->name ?? 'Unit SPPG' }}</h2>

    <table class="meta">
        <tr>
            <td class="label">Nomor Pengadaan</td><td>: {{ $procurement->request_number }}</td>
            <td class="label">Tanggal Permintaan</td><td>: {{ $procurement->request_date?->translatedFormat('d F Y') ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Tanggal Dibutuhkan</td><td>: {{ $procurement->needed_date?->translatedFormat('d F Y') ?? '-' }}</td>
            <td class="label">Status</td><td>: {{ $statusLabel }}</td>
        </tr>
        <tr>
            <td class="label">Catatan</td><td colspan="3">: {{ $procurement->notes ?: '-' }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 4%">No</th>
                <th style="width: 8%">Kode</th>
                <th style="width: 16%">Nama Bahan</th>
                <th style="width: 12%">Kebutuhan Referensi</th>
                <th style="width: 15%">Supplier</th>
                <th style="width: 8%">Jumlah Beli</th>
                <th style="width: 8%">Satuan Beli</th>
                <th style="width: 11%">Harga Satuan</th>
                <th style="width: 12%">Subtotal</th>
                <th>Catatan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($procurement->items as $item)
                @php
                    $referenceQuantity = (float) ($item->requirement_quantity_snapshot ?? 0);
                    $referenceUnit = trim((string) ($item->requirement_unit_snapshot ?? ''));
                @endphp
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td>{{ $item->ingredient_code_snapshot ?: '-' }}</td>
                    <td><strong>{{ $item->ingredient_name_snapshot }}</strong></td>
                    <td class="right">
                        {{ $referenceQuantity > 0 && $referenceUnit !== ''
                            ? number_format($referenceQuantity, 4, ',', '.').' '.$referenceUnit
                            : 'Bahan tambahan' }}
                    </td>
                    <td>{{ $item->supplier?->name ?? '-' }}</td>
                    <td class="right">{{ number_format((float) ($item->approved_quantity ?: $item->requested_quantity), 4, ',', '.') }}</td>
                    <td class="center">{{ $item->unit_snapshot ?: '-' }}</td>
                    <td class="right">Rp {{ number_format((float) $item->estimated_unit_price, 0, ',', '.') }}</td>
                    <td class="right">Rp {{ number_format((float) $item->estimated_total_price, 0, ',', '.') }}</td>
                    <td>{{ $item->notes ?: '-' }}</td>
                </tr>
            @empty
                <tr><td colspan="10" class="center">Tidak ada item pembelian.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="8" class="right">TOTAL PENGADAAN</td>
                <td class="right">Rp {{ number_format((float) $procurement->estimated_total_amount, 0, ',', '.') }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <p class="muted">Kebutuhan referensi berasal dari perhitungan Ahli Gizi. Jumlah dan satuan beli dicatat sesuai rencana pembelian tanpa konversi otomatis.</p>

    <table class="signatures">
        <tr>
            <td>Diajukan oleh,<br>Ahli Gizi<div class="space"></div><strong>{{ $procurement->submitter?->name ?? $procurement->creator?->name ?? '________________' }}</strong></td>
            <td>Diverifikasi oleh,<br>Pengawas Keuangan<div class="space"></div><strong>{{ $procurement->approver?->name ?? '________________' }}</strong></td>
            <td>Disetujui oleh,<br>Kepala SPPG<div class="space"></div><strong>{{ $procurement->priceFinalizer?->name ?? '________________' }}</strong></td>
        </tr>
    </table>

    <p class="muted">Dokumen dibuat oleh Sistem Operasional Terpadu SPPG.</p>
</body>
</html>
