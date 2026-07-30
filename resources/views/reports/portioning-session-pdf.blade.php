<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Form Pengawasan Pengemasan</title>
    <style>
        @page { margin: 24px 34px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111; }
        table { width: 100%; border-collapse: collapse; }
        td, th { border: 1px solid #111; }
        .header td { height: 27px; padding: 3px 6px; }
        .logo-cell { width: 15.5%; text-align: center; vertical-align: middle; }
        .logo { width: 62px; height: 62px; object-fit: contain; }
        .agency { margin-top: 1px; font-size: 8px; font-weight: bold; }
        .title-cell { width: 46.5%; text-align: center; font-size: 14px; font-weight: bold; }
        .title-cell.form { font-size: 13px; }
        .title-cell.document-title { font-size: 13px; }
        .meta-label { width: 13%; font-weight: bold; }
        .meta-value { width: 25%; }
        .date-row { margin: 15px 2px 10px; font-size: 11px; }
        .date-label { display: inline-block; width: 110px; }
        .portion-table th { padding: 5px 4px; font-size: 10px; text-align: center; vertical-align: middle; }
        .portion-table td { height: 24px; padding: 4px 5px; vertical-align: middle; }
        .center { text-align: center; }
        .left { text-align: left; }
        .route { width: 7%; }
        .qty { width: 10%; }
        .time { width: 19%; }
        .menu { width: 22%; }
        .weight { width: 10%; }
        .notes { width: 22%; }
        .signature-wrap { width: 45%; margin-top: 19px; margin-left: 55%; }
        .signature th { height: 28px; padding: 5px; font-size: 10px; }
        .signature td { height: 75px; text-align: center; vertical-align: bottom; padding: 6px; }
        .signature-name { font-weight: bold; }
        .signature-role { margin-top: 2px; font-size: 9px; }
    </style>
</head>
<body>
    @php
        $unitName = strtoupper($session->sppgUnit?->name ?: 'SPPG SLEMAN GAMPING NOGOTIRTO');
        $routeGroups = $session->routeRecords
            ->map(fn ($route) => [
                'route_name' => $route->route_name,
                'small' => (int) $route->small_portions,
                'large' => (int) $route->large_portions,
                'portioned_at' => $route->completed_at,
                'notes' => $route->notes,
            ])
            ->values();
        $leftovers = $session->leftoverRecords->values();
        $rowCount = max(8, $routeGroups->count(), $leftovers->count());
        $coordinatorName = $session->divisionApprover?->name ?: ($session->petugas_name_snapshot ?: '-');
        $verifierName = $session->verifier?->name ?: '-';
    @endphp

    <table class="header">
        <tr>
            <td class="logo-cell" rowspan="3">
                <img class="logo" src="{{ public_path('images/logo-bgn.png') }}" alt="Logo BGN">
                <div class="agency">Badan Gizi Nasional</div>
            </td>
            <td class="title-cell">{{ $unitName }}</td>
            <td class="meta-label">No. Dokumen</td>
            <td class="meta-value">: &nbsp; FR/PN/01</td>
        </tr>
        <tr>
            <td class="title-cell form">FORM</td>
            <td class="meta-label">Revisi</td>
            <td class="meta-value">: &nbsp; 00</td>
        </tr>
        <tr>
            <td class="title-cell document-title">FORM PENGAWASAN PENGEMASAN</td>
            <td class="meta-label">Tanggal Berlaku</td>
            <td class="meta-value">: &nbsp; 01 November 2025</td>
        </tr>
    </table>

    <div class="date-row">
        <span class="date-label">Tanggal</span>
        : &nbsp; {{ $session->portioning_date?->translatedFormat('d F Y') }}
    </div>

    <table class="portion-table">
        <thead>
            <tr>
                <th class="route" rowspan="2">Rute</th>
                <th colspan="2">Qty Ompreng</th>
                <th class="time" rowspan="2">Waktu Pemorsian</th>
                <th colspan="2">Sisa Makanan di Pemorsian</th>
                <th class="notes" rowspan="2">Keterangan</th>
            </tr>
            <tr>
                <th class="qty">Kecil</th>
                <th class="qty">Besar</th>
                <th class="menu">Menu Makanan</th>
                <th class="weight">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @for($index = 0; $index < $rowCount; $index++)
                @php($row = $routeGroups->get($index))
                @php($leftover = $leftovers->get($index))
                <tr>
                    <td class="center">{{ $row ? $row['route_name'] : '' }}</td>
                    <td class="center">{{ $row ? number_format($row['small'], 0, ',', '.') : '' }}</td>
                    <td class="center">{{ $row ? number_format($row['large'], 0, ',', '.') : '' }}</td>
                    <td class="center">{{ $row && $row['portioned_at'] ? $row['portioned_at']->format('H:i') : '' }}</td>
                    <td class="left">{{ $leftover?->food_type ?: '' }}</td>
                    <td class="center">
                        @if($leftover)
                            {{ number_format((float) $leftover->quantity, 3, ',', '.') }} {{ $leftover->unit_name }}
                        @endif
                    </td>
                    <td class="left">{{ collect([$row['notes'] ?? null, $leftover?->notes])->filter()->implode('; ') }}</td>
                </tr>
            @endfor
        </tbody>
    </table>

    <div class="signature-wrap">
        <table class="signature">
            <thead>
                <tr>
                    <th>Koordinator Pemorsian,</th>
                    <th>Diverifikasi Oleh,</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        <div class="signature-name">{{ $coordinatorName }}</div>
                        <div class="signature-role">Kepala Divisi Pemorsian</div>
                    </td>
                    <td>
                        <div class="signature-name">{{ $verifierName }}</div>
                        <div class="signature-role">Kepala SPPG</div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</body>
</html>
