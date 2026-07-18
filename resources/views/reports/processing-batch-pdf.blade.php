<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Batch Pengolahan</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 16px; text-align: center; margin-bottom: 4px; }
        h2 { font-size: 12px; margin: 14px 0 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { border: 1px solid #555; padding: 5px; vertical-align: top; }
        th { background: #eee; }
        .no-border td { border: 0; padding: 2px 0; }
        .small { font-size: 9px; }
    </style>
</head>
<body>
    <h1>LAPORAN BATCH PENGOLAHAN</h1>
    <div style="text-align:center">{{ $batch->sppgUnit?->name }}</div>

    <table class="no-border">
        <tr><td width="22%">Nomor Batch</td><td>: {{ $batch->batch_number }}</td></tr>
        <tr><td>Tanggal</td><td>: {{ $batch->production_date?->format('d-m-Y') }}</td></tr>
        <tr><td>Menu</td><td>: {{ $batch->menu_name_snapshot ?: '-' }}</td></tr>
        <tr><td>Produk</td><td>: {{ $batch->product_name }}</td></tr>
        <tr><td>Penanggung Jawab</td><td>: {{ $batch->petugas_name_snapshot ?: '-' }}</td></tr>
        <tr><td>Mulai - Selesai</td><td>: {{ $batch->started_at?->format('H:i') ?: '-' }} - {{ $batch->completed_at?->format('H:i') ?: '-' }}</td></tr>
        <tr><td>Durasi</td><td>: {{ $batch->duration_minutes !== null ? $batch->duration_minutes . ' menit' : '-' }}</td></tr>
        <tr><td>Hasil Akhir</td><td>: {{ $batch->actual_output_quantity ?? '-' }} {{ $batch->actual_output_unit }}</td></tr>
    </table>

    <h2>Bahan Baku</h2>
    <table>
        <thead><tr><th>No</th><th>Bahan</th><th>Jumlah</th><th>Catatan</th></tr></thead>
        <tbody>
        @forelse($batch->materialUsages as $item)
            <tr><td>{{ $loop->iteration }}</td><td>{{ $item->material_name }}</td><td>{{ $item->quantity }} {{ $item->unit_name }}</td><td>{{ $item->notes ?: '-' }}</td></tr>
        @empty
            <tr><td colspan="4">Tidak ada data.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Pemantauan Suhu</h2>
    <table>
        <thead><tr><th>Waktu</th><th>Titik</th><th>Produk</th><th>Suhu</th><th>Batas</th><th>Status</th><th>Koreksi</th></tr></thead>
        <tbody>
        @forelse($batch->temperatureLogs as $log)
            <tr>
                <td>{{ $log->checked_at?->format('d-m-Y H:i') }}</td>
                <td>{{ $log->checkpoint?->label() }}</td>
                <td>{{ $log->product_name }}</td>
                <td>{{ $log->temperature_celsius }} °C</td>
                <td>{{ $log->minimum_temperature ?? '-' }} s/d {{ $log->maximum_temperature ?? '-' }} °C</td>
                <td>{{ $log->is_within_limit ? 'Sesuai' : 'Di luar batas' }}</td>
                <td>{{ $log->corrective_action ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Tidak ada data.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Penyimpangan</h2>
    <table>
        <thead><tr><th>Kategori</th><th>Keparahan</th><th>Deskripsi</th><th>Tindakan</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($batch->deviations as $deviation)
            <tr>
                <td>{{ $deviation->category }}</td>
                <td>{{ $deviation->severity?->label() }}</td>
                <td>{{ $deviation->description }}</td>
                <td>{{ $deviation->corrective_action ?: '-' }}</td>
                <td>{{ $deviation->status?->label() }}</td>
            </tr>
        @empty
            <tr><td colspan="5">Tidak ada penyimpangan.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Serah-Terima ke Pemorsian</h2>
    <table class="no-border">
        <tr><td width="22%">Waktu</td><td>: {{ $batch->handover?->handed_over_at?->format('d-m-Y H:i') ?: '-' }}</td></tr>
        <tr><td>Jumlah</td><td>: {{ $batch->handover?->output_quantity ?? '-' }} {{ $batch->handover?->unit_name }}</td></tr>
        <tr><td>Penerima</td><td>: {{ $batch->handover?->received_by_name ?: '-' }}</td></tr>
        <tr><td>Catatan</td><td>: {{ $batch->handover?->notes ?: '-' }}</td></tr>
    </table>

    <p class="small">Status laporan: {{ $batch->status?->label() }}. Dicetak pada {{ now()->format('d-m-Y H:i') }}.</p>
</body>
</html>
