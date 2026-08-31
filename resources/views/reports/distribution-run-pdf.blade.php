<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Seluruh Rute Distribusi</title>
    <style>
        @page { margin: 24px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8px; color: #111827; }
        h1 { margin: 0 0 3px; font-size: 15px; text-align: center; }
        h2 { margin: 11px 0 5px; padding: 5px 7px; background: #e8eef7; font-size: 10px; }
        table { width: 100%; margin-bottom: 7px; border-collapse: collapse; }
        th, td { padding: 4px; border: 1px solid #64748b; vertical-align: top; }
        th { background: #dbe7f5; font-weight: bold; text-align: center; }
        .no-border td { padding: 2px 4px; border: 0; }
        .center { text-align: center; }
        .small { font-size: 7px; color: #475569; }
        .summary { margin-top: 8px; }
        .summary td { width: 16.66%; padding: 6px; text-align: center; }
        .summary strong { display: block; margin-top: 2px; font-size: 11px; }
    </style>
</head>
<body>
    @php
        $routeCount = $runs->count();
        $allStops = $runs->flatMap(fn ($routeRun) => $routeRun->stops->map(
            fn ($stop) => ['run' => $routeRun, 'stop' => $stop]
        ));
        $allIncidents = $runs->flatMap(fn ($routeRun) => $routeRun->incidents->map(
            fn ($incident) => ['run' => $routeRun, 'incident' => $incident]
        ));
        $loadedSmall = (int) $runs->sum('loaded_small_portions');
        $loadedLarge = (int) $runs->sum('loaded_large_portions');
        $deliveredSmall = (int) $runs->sum('delivered_small_portions');
        $deliveredLarge = (int) $runs->sum('delivered_large_portions');
        $returnedSmall = (int) $runs->sum('returned_small_portions');
        $returnedLarge = (int) $runs->sum('returned_large_portions');
        $unaccounted = (int) $runs->sum(fn ($routeRun) => $routeRun->unaccounted_total);
    @endphp

    <h1>LAPORAN KESELURUHAN RUTE DISTRIBUSI</h1>
    <div class="center">{{ $run->sppgUnit?->name }}</div>

    <table class="no-border" style="margin-top: 8px;">
        <tr>
            <td width="16%">Nomor Rencana</td><td width="34%">: {{ $plan?->plan_number ?: $run->run_number }}</td>
            <td width="16%">Tanggal Distribusi</td><td>: {{ $run->distribution_date?->format('d-m-Y') }}</td>
        </tr>
        <tr>
            <td>Jumlah Rute</td><td>: {{ $routeCount }} rute</td>
            <td>Jumlah Tujuan</td><td>: {{ $allStops->count() }} tujuan</td>
        </tr>
        <tr>
            <td>Menu/Produk</td><td>: {{ $plan?->menu_name_snapshot ?: ($runs->pluck('menu_name_snapshot')->filter()->unique()->implode(', ') ?: '-') }}</td>
            <td>Status Rencana</td><td>: {{ $plan?->status?->label() ?: '-' }}</td>
        </tr>
    </table>

    <table class="summary">
        <tr>
            <td>Dimuat Kecil<strong>{{ number_format($loadedSmall, 0, ',', '.') }}</strong></td>
            <td>Dimuat Besar<strong>{{ number_format($loadedLarge, 0, ',', '.') }}</strong></td>
            <td>Terkirim Kecil<strong>{{ number_format($deliveredSmall, 0, ',', '.') }}</strong></td>
            <td>Terkirim Besar<strong>{{ number_format($deliveredLarge, 0, ',', '.') }}</strong></td>
            <td>Kembali K/B<strong>{{ number_format($returnedSmall, 0, ',', '.') }}/{{ number_format($returnedLarge, 0, ',', '.') }}</strong></td>
            <td>Belum Terjelaskan<strong>{{ number_format($unaccounted, 0, ',', '.') }}</strong></td>
        </tr>
    </table>

    <h2>A. RINGKASAN SELURUH RUTE</h2>
    <table>
        <thead>
            <tr>
                <th>No</th><th>Nama Rute</th><th>Pengemudi/Kernet</th><th>Kendaraan</th>
                <th>Berangkat/Kembali</th><th>Dimuat K/B</th><th>Terkirim K/B</th><th>Kembali K/B</th>
                <th>Ompreng Kembali/Rusak/Hilang</th><th>Status Perjalanan</th><th>Status Laporan</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($runs as $index => $routeRun)
            <tr>
                <td class="center">{{ $index + 1 }}</td>
                <td>{{ $routeRun->route_name ?: 'Rute '.($index + 1) }}<br><span class="small">{{ $routeRun->run_number }}</span></td>
                <td>{{ $routeRun->driver_name ?: '-' }}<br><span class="small">Kernet: {{ $routeRun->kernet_name ?: '-' }}</span></td>
                <td>{{ $routeRun->vehicle_name ?: '-' }}<br><span class="small">{{ $routeRun->vehicle_plate ?: '-' }}</span></td>
                <td class="center">{{ $routeRun->actual_departure_at?->format('H:i') ?: '-' }} / {{ $routeRun->returned_at?->format('H:i') ?: '-' }}</td>
                <td class="center">{{ $routeRun->loaded_small_portions }}/{{ $routeRun->loaded_large_portions }}</td>
                <td class="center">{{ $routeRun->delivered_small_portions }}/{{ $routeRun->delivered_large_portions }}</td>
                <td class="center">{{ $routeRun->returned_small_portions }}/{{ $routeRun->returned_large_portions }}</td>
                <td class="center">{{ $routeRun->containers_returned }}/{{ $routeRun->containers_damaged }}/{{ $routeRun->containers_lost }}</td>
                <td>{{ $routeRun->state?->label() ?: '-' }}</td>
                <td>{{ $routeRun->status?->label() ?: '-' }}</td>
            </tr>
            @empty
            <tr><td colspan="11" class="center">Tidak ada data rute distribusi.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>B. TUJUAN DARI SELURUH RUTE</h2>
    <table>
        <thead>
            <tr>
                <th>No</th><th>Rute</th><th>Urutan</th><th>Tujuan</th><th>Jadwal/Tiba</th>
                <th>Rencana K/B</th><th>Diserahkan K/B</th><th>Ompreng/Wadah</th>
                <th>Tidak Tersalurkan K/B</th><th>Penerima</th><th>Status</th><th>Alasan Selisih/Gagal</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($allStops as $index => $row)
                @php($routeRun = $row['run'])
                @php($stop = $row['stop'])
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $routeRun->route_name ?: '-' }}</td>
                    <td class="center">{{ $stop->sequence_order }}</td>
                    <td>{{ $stop->destination_name }}<br><span class="small">{{ $stop->address ?: '-' }}</span></td>
                    <td class="center">{{ $stop->planned_arrival_at?->format('H:i') ?: '-' }} / {{ $stop->arrived_at?->format('H:i') ?: '-' }}</td>
                    <td class="center">{{ $stop->small_portions }}/{{ $stop->large_portions }}</td>
                    <td class="center">{{ $stop->delivered_small_portions }}/{{ $stop->delivered_large_portions }}</td>
                    <td class="center">{{ $stop->containers_sent }}</td>
                    <td class="center">{{ $stop->returned_small_portions }}/{{ $stop->returned_large_portions }}</td>
                    <td>{{ $stop->recipient_name ?: '-' }}</td>
                    <td>{{ $stop->status?->label() ?: '-' }}</td>
                    <td>{{ $stop->failure_reason ?: '-' }}</td>
                </tr>
            @empty
            <tr><td colspan="12" class="center">Tidak ada data tujuan.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>C. INSIDEN SELURUH RUTE</h2>
    <table>
        <thead><tr><th>No</th><th>Rute</th><th>Waktu</th><th>Tujuan</th><th>Kategori</th><th>Tingkat</th><th>Deskripsi</th><th>Tindakan</th><th>Status</th></tr></thead>
        <tbody>
            @forelse ($allIncidents as $index => $row)
                @php($routeRun = $row['run'])
                @php($incident = $row['incident'])
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $routeRun->route_name ?: '-' }}</td>
                    <td>{{ $incident->occurred_at?->format('d-m-Y H:i') ?: '-' }}</td>
                    <td>{{ $incident->stop?->destination_name ?: '-' }}</td>
                    <td>{{ $incident->category }}</td>
                    <td>{{ $incident->severity?->label() ?: '-' }}</td>
                    <td>{{ $incident->description }}</td>
                    <td>{{ $incident->immediate_action ?: '-' }}</td>
                    <td>{{ $incident->status?->label() ?: '-' }}</td>
                </tr>
            @empty
            <tr><td colspan="9" class="center">Tidak ada insiden.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="small">Dicetak pada {{ now()->format('d-m-Y H:i') }}. Laporan ini mencakup seluruh rute dalam rencana distribusi yang sama.</p>
</body>
</html>
