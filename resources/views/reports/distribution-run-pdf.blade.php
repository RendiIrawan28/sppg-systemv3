<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Distribusi</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111; }
        h1 { font-size: 15px; text-align: center; margin-bottom: 3px; }
        h2 { font-size: 11px; margin: 12px 0 5px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 7px; }
        th, td { border: 1px solid #555; padding: 4px; vertical-align: top; }
        th { background: #eee; }
        .no-border td { border: 0; padding: 2px 0; }
        .small { font-size: 8px; }
    </style>
</head>
<body>
    <h1>LAPORAN PERJALANAN DISTRIBUSI</h1>
    <div style="text-align:center">{{ $run->sppgUnit?->name }}</div>

    <table class="no-border">
        <tr><td width="18%">Nomor Perjalanan</td><td>: {{ $run->run_number }}</td><td width="18%">Tanggal</td><td>: {{ $run->distribution_date?->format('d-m-Y') }}</td></tr>
        <tr><td>Nama Rute</td><td>: {{ $run->route_name ?: 'Rute Utama' }}</td><td>Status Laporan</td><td>: {{ $run->status?->label() }}</td></tr>
        <tr><td>Menu/Produk</td><td>: {{ $run->menu_name_snapshot }}</td><td>Penanggung Jawab</td><td>: {{ $run->petugas_name_snapshot ?: '-' }}</td></tr>
        <tr><td>Kendaraan</td><td>: {{ $run->vehicle_name ?: '-' }} / {{ $run->vehicle_plate ?: '-' }}</td><td>Pengemudi</td><td>: {{ $run->driver_name ?: '-' }}</td></tr>
        <tr><td>Kernet</td><td>: {{ $run->kernet_name ?: '-' }}</td><td>Status Perjalanan</td><td>: {{ $run->state?->label() }}</td></tr>
        <tr><td>Berangkat</td><td>: {{ $run->actual_departure_at?->format('d-m-Y H:i') ?: '-' }}</td><td>Kembali</td><td>: {{ $run->returned_at?->format('d-m-Y H:i') ?: '-' }}</td></tr>
        <tr><td>Suhu Berangkat</td><td>: {{ $run->departure_temperature_celsius !== null ? $run->departure_temperature_celsius . ' °C' : '-' }}</td><td>Durasi</td><td>: {{ $run->duration_minutes !== null ? $run->duration_minutes . ' menit' : '-' }}</td></tr>
        <tr><td>Muatan</td><td>: {{ $run->loaded_small_portions }} kecil + {{ $run->loaded_large_portions }} besar</td><td>Terkirim</td><td>: {{ $run->delivered_small_portions }} kecil + {{ $run->delivered_large_portions }} besar</td></tr>
        <tr><td>Tidak Tersalurkan</td><td>: {{ $run->returned_small_portions }} kecil + {{ $run->returned_large_portions }} besar</td><td>Belum Terjelaskan</td><td>: {{ $run->unaccounted_total }}</td></tr>
    </table>

    <h2>Tujuan Distribusi</h2>
    <table>
        <thead>
            <tr>
                <th>No</th><th>Tujuan</th><th>Jadwal</th><th>Serah-terima</th><th>Rencana K/B</th><th>Diserahkan K/B</th><th>Ompreng/wadah</th><th>Tidak tersalurkan K/B</th><th>Penerima</th><th>Status</th><th>Alasan selisih/gagal</th>
            </tr>
        </thead>
        <tbody>
        @forelse($run->stops as $stop)
            <tr>
                <td>{{ $stop->sequence_order }}</td>
                <td>{{ $stop->destination_name }}<br><span class="small">{{ $stop->address ?: '-' }}</span></td>
                <td>{{ $stop->planned_arrival_at?->format('H:i') ?: '-' }}</td>
                <td>{{ $stop->arrived_at?->format('H:i') ?: '-' }}<br>{{ $stop->delay_minutes }} mnt terlambat</td>
                <td>{{ $stop->small_portions }}/{{ $stop->large_portions }}</td>
                <td>{{ $stop->delivered_small_portions }}/{{ $stop->delivered_large_portions }}</td>
                <td>{{ $stop->containers_sent }}</td>
                <td>{{ $stop->returned_small_portions }}/{{ $stop->returned_large_portions }}</td>
                <td>{{ $stop->recipient_name ?: '-' }}</td>
                <td>{{ $stop->status?->label() }}</td>
                <td>{{ $stop->failure_reason ?: '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="11">Tidak ada data tujuan.</td></tr>
        @endforelse
        </tbody>
    </table>

    <h2>Insiden</h2>
    <table>
        <thead><tr><th>Waktu</th><th>Tujuan</th><th>Kategori</th><th>Tingkat</th><th>Deskripsi</th><th>Tindakan</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($run->incidents as $incident)
            <tr>
                <td>{{ $incident->occurred_at?->format('d-m-Y H:i') }}</td>
                <td>{{ $incident->stop?->destination_name ?: '-' }}</td>
                <td>{{ $incident->category }}</td>
                <td>{{ $incident->severity?->label() }}</td>
                <td>{{ $incident->description }}</td>
                <td>{{ $incident->immediate_action ?: '-' }}</td>
                <td>{{ $incident->status?->label() }}</td>
            </tr>
        @empty
            <tr><td colspan="7">Tidak ada insiden.</td></tr>
        @endforelse
        </tbody>
    </table>

    <p class="small">Dicetak pada {{ now()->format('d-m-Y H:i') }}.</p>
</body>
</html>
