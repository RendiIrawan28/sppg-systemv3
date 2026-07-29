<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Laporan Harian Pencucian Ompreng</title>
    <style>
        @page { margin: 22px 25px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #111827; }
        h1 { margin: 0 0 4px; font-size: 17px; }
        h2 { margin: 15px 0 6px; font-size: 11px; }
        p { margin: 3px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #cbd5e1; padding: 5px; vertical-align: top; }
        th { background: #e2e8f0; text-align: left; }
        .number { text-align: right; }
        .center { text-align: center; }
        .muted { color: #64748b; }
        .good { color: #047857; font-weight: bold; }
        .summary { margin-top: 10px; }
        .summary td { width: 16.66%; }
        .summary strong { display: block; margin-top: 3px; font-size: 14px; }
        .signature { margin-top: 24px; }
        .signature td { height: 72px; text-align: center; vertical-align: bottom; width: 33.33%; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    @php
        $reference = $sessions->first();
        $totalExpected = (int) $sessions->sum('expected_containers');
        $totalReceived = (int) $sessions->sum('received_containers');
        $totalClean = (int) $sessions->sum('clean_containers');
        $totalDamaged = (int) $sessions->sum('damaged_containers');
        $totalMissing = (int) $sessions->sum('missing_containers');
        $wasteItems = $sessions->flatMap(fn ($washing) => $washing->wasteRecords->map(function ($item) use ($washing) {
            $item->route_label = $washing->containerCollectionRun?->run_number ?: $washing->distributionRun?->route_name ?: $washing->session_number;
            return $item;
        }));
        $wasteTotals = $wasteItems->groupBy(fn ($item) => $item->unit ?: 'satuan')
            ->map(fn ($items) => $items->sum(fn ($item) => (float) $item->quantity));
    @endphp

    <h1>Laporan Harian Pencucian Ompreng</h1>
    <p class="muted">{{ $reference->sppgUnit?->name }} · {{ $reference->washing_date?->translatedFormat('d F Y') }}</p>

    <table class="summary">
        <tr>
            <td>Jumlah sesi<strong>{{ number_format($sessions->count(), 0, ',', '.') }}</strong></td>
            <td>Seharusnya diterima<strong>{{ number_format($totalExpected, 0, ',', '.') }}</strong></td>
            <td>Diterima fisik<strong>{{ number_format($totalReceived, 0, ',', '.') }}</strong></td>
            <td>Bersih/siap digunakan<strong>{{ number_format($totalClean, 0, ',', '.') }}</strong></td>
            <td>Rusak/tidak layak<strong>{{ number_format($totalDamaged, 0, ',', '.') }}</strong></td>
            <td>Kurang saat serah-terima<strong>{{ number_format($totalMissing, 0, ',', '.') }}</strong></td>
        </tr>
    </table>

    <h2>Rekap per rute</h2>
    <table>
        <thead>
            <tr>
                <th style="width:4%">No.</th>
                <th>Rute / sesi</th>
                <th>Driver</th>
                <th>Petugas Pencucian</th>
                <th>Seharusnya</th>
                <th>Diterima</th>
                <th>Bersih</th>
                <th>Rusak</th>
                <th>Kurang</th>
                <th>Limbah</th>
                <th>Mulai–selesai</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sessions as $washing)
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td><strong>{{ $washing->containerCollectionRun?->run_number ?: $washing->distributionRun?->route_name ?: 'Pengambilan Ompreng' }}</strong><br><span class="muted">{{ $washing->session_number }}</span></td>
                    <td>{{ $washing->containerCollectionRun?->driver_name_snapshot ?: $washing->distributionRun?->driver_name ?: '—' }}</td>
                    <td>{{ $washing->petugas?->name ?: $washing->petugas_name_snapshot ?: '—' }}</td>
                    <td class="number">{{ number_format($washing->expected_containers, 0, ',', '.') }}</td>
                    <td class="number">{{ number_format($washing->received_containers, 0, ',', '.') }}</td>
                    <td class="number">{{ number_format($washing->clean_containers, 0, ',', '.') }}</td>
                    <td class="number">{{ number_format($washing->damaged_containers, 0, ',', '.') }}</td>
                    <td class="number">{{ number_format($washing->missing_containers, 0, ',', '.') }}</td>
                    <td>{{ $washing->has_food_waste ? $washing->wasteRecords->count().' catatan' : 'Tidak ada' }}</td>
                    <td>{{ $washing->started_at?->format('H:i') ?: '—' }}–{{ $washing->completed_at?->format('H:i') ?: '—' }}<br><span class="muted">{{ number_format($washing->duration_minutes, 0, ',', '.') }} menit</span></td>
                    <td class="good">{{ $washing->state?->label() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Rekap limbah makanan</h2>
    @if ($wasteItems->isNotEmpty())
        <p>
            <strong>Total per satuan:</strong>
            @foreach ($wasteTotals as $unit => $quantity)
                {{ !$loop->first ? ' · ' : '' }}{{ number_format((float) $quantity, 3, ',', '.') }} {{ $unit }}
            @endforeach
        </p>
        <table>
            <thead><tr><th style="width:4%">No.</th><th>Rute</th><th>Jenis limbah</th><th>Jumlah</th><th>Penanganan</th><th>Penerima</th><th>Catatan</th></tr></thead>
            <tbody>
                @foreach ($wasteItems as $item)
                    <tr>
                        <td class="center">{{ $loop->iteration }}</td>
                        <td>{{ $item->route_label }}</td>
                        <td>{{ $item->waste_type }}</td>
                        <td class="number">{{ number_format((float) $item->quantity, 3, ',', '.') }} {{ $item->unit }}</td>
                        <td>{{ $item->disposal_method }}</td>
                        <td>{{ $item->handed_over_to }}</td>
                        <td>{{ $item->notes ?: '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="good">Seluruh sesi dikonfirmasi tidak memiliki limbah makanan.</p>
    @endif

    <h2>Catatan dan rekonsiliasi</h2>
    <table>
        <thead><tr><th>Rute</th><th>Selisih penerimaan</th><th>Rekonsiliasi hasil</th><th>Catatan</th></tr></thead>
        <tbody>
            @foreach ($sessions as $washing)
                <tr>
                    <td>{{ $washing->containerCollectionRun?->run_number ?: $washing->distributionRun?->route_name ?: $washing->session_number }}</td>
                    <td class="number">{{ $washing->receiving_difference > 0 ? '+' : '' }}{{ number_format($washing->receiving_difference, 0, ',', '.') }}</td>
                    <td class="center {{ $washing->processing_difference === 0 ? 'good' : '' }}">{{ $washing->processing_difference === 0 ? 'Seimbang' : 'Selisih '.$washing->processing_difference }}</td>
                    <td>{{ $washing->notes ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="signature">
        <tr>
            <td>Tim Pencucian<br><br><br><strong>{{ $sessions->pluck('petugas_name_snapshot')->filter()->unique()->implode(', ') ?: '........................' }}</strong></td>
            <td>Kepala Divisi Pencucian<br><br><br><strong>{{ $reference->divisionApprover?->name ?: '........................' }}</strong></td>
            <td>Kepala SPPG<br><br><br><strong>{{ $reference->verifier?->name ?: '........................' }}</strong></td>
        </tr>
    </table>
</body>
</html>
