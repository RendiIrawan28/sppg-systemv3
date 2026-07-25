<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Berita Acara Perhitungan Tim Persiapan</title>
    <style>
        @page { size: A4 portrait; margin: 38px 38px 42px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #000; }
        table { width: 100%; border-collapse: collapse; }
        .letterhead { border-bottom: 3px solid #111; margin-bottom: 19px; padding: 0 10px 6px; }
        .letterhead td { border: 0; vertical-align: middle; }
        .logo-cell { width: 16%; text-align: center; }
        .logo { width: 72px; height: 72px; object-fit: contain; }
        .identity { text-align: center; line-height: 1.35; }
        .identity .agency { font-size: 12px; font-weight: bold; }
        .identity .unit { margin-top: 5px; font-size: 12px; font-weight: bold; }
        .identity .address { margin-top: 4px; font-size: 9px; font-weight: normal; }
        .title { margin: 0 0 13px; text-align: center; font-size: 12px; font-weight: bold; }
        .date { margin: 0 0 10px 42px; font-size: 10px; }
        .items { table-layout: fixed; }
        .items th, .items td { height: 20px; border: 0.7px solid #111; padding: 3px 4px; vertical-align: middle; }
        .items th { height: 32px; text-align: center; font-size: 9px; font-weight: normal; }
        .items .no { width: 6.5%; }
        .items .name { width: 20%; }
        .items .amount { width: 16.5%; }
        .items .unit { width: 11%; }
        .items .quality { width: 9.5%; }
        .items .notes { width: 15.5%; }
        .center { text-align: center; }
        .right { text-align: right; }
        .checkmark { text-align: center; font-size: 14px; font-weight: bold; }
        .signature { margin: 27px 0 0 42px; width: 190px; font-size: 10px; }
        .signature-title { font-weight: bold; }
        .signature-space { height: 53px; }
        .signature-name { white-space: nowrap; }
    </style>
</head>
<body>
    @php
        $unit = $session->sppgUnit;
        $itemCount = $session->items->count();
        $fillerRows = max(0, 22 - $itemCount);
    @endphp

    <div class="letterhead">
        <table>
            <tr>
                <td class="logo-cell">
                    <img class="logo" src="{{ public_path('images/logo-bgn.png') }}" alt="Logo BGN">
                </td>
                <td class="identity">
                    <div class="agency">BADAN GIZI NASIONAL</div>
                    <div class="unit">SATUAN PELAYANAN PEMENUHAN GIZI {{ strtoupper(str($unit?->name ?? 'SPPG')->replace('SPPG', '')->trim()) }}</div>
                    <div class="address">{{ $unit?->address ?: 'Alamat Unit SPPG belum diisi' }}</div>
                </td>
                <td style="width: 16%"></td>
            </tr>
        </table>
    </div>

    <div class="title">BERITA ACARA PERHITUNGAN TIM PERSIAPAN</div>
    <p class="date">Hari, Tanggal&nbsp;&nbsp;&nbsp;: {{ $session->preparation_date?->translatedFormat('l, d F Y') }}</p>

    <table class="items">
        <thead>
            <tr>
                <th class="no">No</th>
                <th class="name">Nama Bahan</th>
                <th class="amount">Banyaknya<br>(Angka)</th>
                <th class="unit">Satuan</th>
                <th class="quality">Baik</th>
                <th class="quality">Rusak</th>
                <th class="quality">Sedang</th>
                <th class="notes">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($session->items as $item)
                @php
                    $received = (float) ($item->received_quantity ?? $item->received_weight_kg);
                    $condition = strtolower((string) $item->condition_status);
                    $isGood = in_array($condition, ['good', 'accepted'], true);
                    $isDamaged = in_array($condition, ['damaged', 'rejected'], true);
                    $isModerate = in_array($condition, ['moderate', 'medium'], true);
                @endphp
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td>{{ $item->ingredient_name_snapshot }}</td>
                    <td class="center">{{ number_format($received, 3, ',', '.') }}</td>
                    <td class="center">{{ $item->unit_snapshot }}</td>
                    <td class="checkmark">{{ $isGood ? '✓' : '' }}</td>
                    <td class="checkmark">{{ $isDamaged ? '✓' : '' }}</td>
                    <td class="checkmark">{{ $isModerate ? '✓' : '' }}</td>
                    <td>{{ $item->notes ?: '-' }}</td>
                </tr>
            @endforeach
            @for ($row = 0; $row < $fillerRows; $row++)
                <tr>
                    <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                </tr>
            @endfor
        </tbody>
    </table>

    <div class="signature">
        <div class="signature-title">Dihitung Oleh</div>
        <div class="signature-space"></div>
        <div class="signature-name">( {{ $session->petugas?->name ?: '.................................' }} )</div>
    </div>
</body>
</html>
