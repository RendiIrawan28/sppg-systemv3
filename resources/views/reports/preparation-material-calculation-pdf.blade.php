<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Berita Acara Perhitungan Tim Persiapan</title>
    <style>
        @page { margin: 26px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        .header { text-align: center; line-height: 1.35; margin-bottom: 18px; }
        .header .agency { font-weight: bold; font-size: 13px; }
        .header .unit { font-weight: bold; font-size: 12px; }
        .header .address { font-size: 10px; }
        .title { text-align: center; font-size: 14px; font-weight: bold; margin: 16px 0; text-decoration: underline; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 2px 0; }
        .items th, .items td { border: 1px solid #333; padding: 5px 4px; vertical-align: middle; }
        .items th { text-align: center; font-weight: bold; background: #f1f1f1; }
        .center { text-align: center; }
        .right { text-align: right; }
        .signature { margin-top: 34px; width: 45%; margin-left: auto; }
        .signature td { text-align: center; padding: 4px; }
        .sign-space { height: 60px; }
        .muted { color: #555; font-size: 10px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="agency">BADAN GIZI NASIONAL</div>
        <div class="unit">SATUAN PELAYANAN PEMENUHAN GIZI NOGOTIRTO</div>
        <div class="address">Jl. Niten No.8, Karang Wetan, Nogotirto, Kec Gamping</div>
        <div class="address">Kabupaten Sleman, Daerah Istimewa Yogyakarta 55292</div>
    </div>

    <div class="title">BERITA ACARA PERHITUNGAN TIM PERSIAPAN</div>

    <table class="meta" style="margin-bottom: 12px;">
        <tr>
            <td style="width: 18%">Hari, Tanggal</td>
            <td>: {{ $handover->handover_date?->translatedFormat('l, d F Y') }}</td>
        </tr>
        <tr>
            <td>Nomor</td>
            <td>: {{ $handover->handover_number }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 5%">No</th>
                <th style="width: 22%">Nama Bahan</th>
                <th style="width: 12%">Banyaknya<br>(Angka)</th>
                <th style="width: 9%">Satuan</th>
                <th style="width: 10%">Baik</th>
                <th style="width: 10%">Rusak</th>
                <th style="width: 10%">Sedang</th>
                <th>Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($handover->items as $item)
                <tr>
                    <td class="center">{{ $loop->iteration }}</td>
                    <td>{{ $item->ingredient_name_snapshot }}</td>
                    <td class="right">{{ number_format($item->displayQuantity('received_quantity', 'handed_over_quantity'), 2, ',', '.') }}</td>
                    <td class="center">{{ $item->unit_snapshot ?: '-' }}</td>
                    <td class="right">{{ number_format((float) $item->good_quantity, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format((float) $item->damaged_quantity, 2, ',', '.') }}</td>
                    <td class="right">{{ number_format((float) $item->moderate_quantity, 2, ',', '.') }}</td>
                    <td>{{ $item->inspection_notes ?: $item->notes }}</td>
                </tr>
            @endforeach
            @for ($i = $handover->items->count(); $i < 18; $i++)
                <tr>
                    <td class="center">&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                </tr>
            @endfor
        </tbody>
    </table>

    <table class="signature">
        <tr><td>Dihitung Oleh</td></tr>
        <tr><td class="sign-space"></td></tr>
        <tr><td>( {{ $handover->preparation_officer_name ?: '.................................' }} )</td></tr>
    </table>

    <p class="muted">
        Status dokumen: {{ \App\Support\V3\OperationsPresentation::handoverStatuses()[$handover->status] ?? str($handover->status)->headline() }}
    </p>
</body>
</html>
