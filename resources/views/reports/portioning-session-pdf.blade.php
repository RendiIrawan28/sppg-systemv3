<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Pemorsian</title>
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
    <h1>LAPORAN PEMORSIAN TERPADU</h1>
    <div style="text-align:center">{{ $session->sppgUnit?->name }}</div>

    <table class="no-border">
        <tr><td width="24%">Nomor Sesi</td><td>: {{ $session->session_number }}</td></tr>
        <tr><td>Tanggal</td><td>: {{ $session->portioning_date?->format('d-m-Y') }}</td></tr>
        <tr><td>Menu/Produk</td><td>: {{ $session->menu_name_snapshot }}</td></tr>
        <tr><td>Penanggung Jawab</td><td>: {{ $session->petugas_name_snapshot ?: '-' }}</td></tr>
        <tr><td>Mulai - Selesai</td><td>: {{ $session->started_at?->format('H:i') ?: '-' }} - {{ $session->completed_at?->format('H:i') ?: '-' }}</td></tr>
        <tr><td>Durasi</td><td>: {{ $session->duration_minutes !== null ? $session->duration_minutes . ' menit' : '-' }}</td></tr>
        <tr><td>Target</td><td>: {{ $session->target_small_portions }} kecil + {{ $session->target_large_portions }} besar = {{ $session->target_total }}</td></tr>
        <tr><td>Realisasi</td><td>: {{ $session->actual_small_portions }} kecil + {{ $session->actual_large_portions }} besar = {{ $session->actual_total }}</td></tr>
        <tr><td>Selisih</td><td>: {{ $session->difference_total }}</td></tr>
    </table>

    <h2>Pembagian per Rute</h2>
    <table>
        <thead><tr><th>No</th><th>Rute/Tujuan</th><th>Target Kecil</th><th>Target Besar</th><th>Aktual Kecil</th><th>Aktual Besar</th></tr></thead>
        <tbody>
        @forelse($session->routeAllocations as $route)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $route->route_name }}</td>
                <td>{{ $route->target_small_portions }}</td>
                <td>{{ $route->target_large_portions }}</td>
                <td>{{ $route->actual_small_portions }}</td>
                <td>{{ $route->actual_large_portions }}</td>
            </tr>
        @empty
            <tr><td colspan="6">Tidak ada data.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Sampel Berat</h2>
    <table>
        <thead><tr><th>Waktu</th><th>Ukuran</th><th>Komponen</th><th>Target</th><th>Aktual</th><th>Deviasi</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($session->weightSamples as $sample)
            <tr>
                <td>{{ $sample->checked_at?->format('d-m-Y H:i') }}</td>
                <td>{{ $sample->portion_size?->label() }}</td>
                <td>{{ $sample->component_name }}</td>
                <td>{{ $sample->target_weight_grams }} g</td>
                <td>{{ $sample->actual_weight_grams }} g</td>
                <td>{{ $sample->deviation_grams }} g</td>
                <td>{{ $sample->is_within_tolerance ? 'Sesuai' : 'Di luar toleransi' }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Tidak ada data.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Sisa Makanan</h2>
    <table>
        <thead><tr><th>Waktu</th><th>Rute</th><th>Jenis</th><th>Berat</th><th>Penyebab</th><th>Catatan</th></tr></thead>
        <tbody>
        @forelse($session->leftoverRecords as $leftover)
            <tr>
                <td>{{ $leftover->checked_at?->format('d-m-Y H:i') }}</td>
                <td>{{ $leftover->route_name ?: '-' }}</td>
                <td>{{ $leftover->food_type }}</td>
                <td>{{ $leftover->weight_kg }} kg</td>
                <td>{{ $leftover->reason ?: '-' }}</td>
                <td>{{ $leftover->notes ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="6">Tidak ada sisa makanan yang dicatat.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Penyimpangan</h2>
    <table>
        <thead><tr><th>Kategori</th><th>Keparahan</th><th>Deskripsi</th><th>Tindakan</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($session->deviations as $deviation)
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

    <h2>Serah-Terima ke Distribusi</h2>
    <table class="no-border">
        <tr><td width="24%">Waktu</td><td>: {{ $session->handover?->handed_over_at?->format('d-m-Y H:i') ?: '-' }}</td></tr>
        <tr><td>Jumlah</td><td>: {{ $session->handover?->small_portions ?? '-' }} kecil, {{ $session->handover?->large_portions ?? '-' }} besar</td></tr>
        <tr><td>Penerima</td><td>: {{ $session->handover?->received_by_name ?: '-' }}</td></tr>
        <tr><td>Catatan</td><td>: {{ $session->handover?->notes ?: '-' }}</td></tr>
    </table>

    <p class="small">Status laporan: {{ $session->status?->label() }}. Dicetak pada {{ now()->format('d-m-Y H:i') }}.</p>
</body>
</html>
