<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Pemantauan Suhu Pengolahan dan Penyajian</title>
    <style>
        @page { size: A4 landscape; margin: 20px 24px 26px; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #000; font-family: DejaVu Sans, sans-serif; font-size: 9px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: 1px solid #111; padding: 5px; vertical-align: middle; }
        .header td { height: 31px; }
        .logo-cell { width: 26%; text-align: center; }
        .logo { width: 74px; height: 74px; object-fit: contain; }
        .agency { margin-top: 4px; font-size: 9px; font-weight: bold; }
        .identity { width: 42%; text-align: center; font-weight: bold; font-size: 12px; }
        .identity-title { font-size: 11px; }
        .document-label { width: 15%; font-weight: bold; font-size: 10px; }
        .document-value { width: 17%; font-size: 10px; }
        .monitor { margin-top: 14px; }
        .monitor th { height: 33px; text-align: center; font-size: 10px; }
        .monitor td { height: 38px; font-size: 9px; }
        .date { width: 14%; }
        .time { width: 12%; }
        .product { width: 29%; }
        .temperature { width: 13%; }
        .officer { width: 18%; }
        .initial { width: 14%; }
        .center { text-align: center; }
        .page-break { page-break-before: always; }
        .documentation-title { margin: 0 0 10px; text-align: center; font-size: 13px; font-weight: bold; }
        .photo-card { width: 33.33%; height: 185px; text-align: center; vertical-align: top; }
        .photo { max-width: 100%; max-height: 130px; object-fit: contain; }
        .caption { margin-top: 4px; font-size: 8px; line-height: 1.35; }
    </style>
</head>
<body>
    @php
        $unitName = strtoupper($anchorBatch->sppgUnit?->name ?: 'SPPG');
        $fillerRows = max(0, 10 - $logs->count());
    @endphp

    <table class="header">
        <tr>
            <td class="logo-cell" rowspan="3">
                <img class="logo" src="{{ public_path('images/logo-bgn.png') }}" alt="Logo BGN">
                <div class="agency">Badan Gizi Nasional</div>
            </td>
            <td class="identity">{{ $unitName }}</td>
            <td class="document-label">No. Dokumen</td>
            <td class="document-value">: FR/PN/07</td>
        </tr>
        <tr>
            <td class="identity">FORM</td>
            <td class="document-label">Revisi</td>
            <td class="document-value">: 00</td>
        </tr>
        <tr>
            <td class="identity identity-title">PEMANTAUAN SUHU PENGOLAHAN &amp; PENYAJIAN</td>
            <td class="document-label">Tanggal Berlaku</td>
            <td class="document-value">: 9 Februari 2026</td>
        </tr>
    </table>

    <table class="monitor">
        <thead>
            <tr>
                <th class="date">Tanggal</th>
                <th class="time">Waktu</th>
                <th class="product">Nama Produk</th>
                <th class="temperature">Suhu Produk</th>
                <th class="officer">Nama Petugas</th>
                <th class="initial">Paraf</th>
            </tr>
        </thead>
        <tbody>
            @foreach($logs as $log)
                <tr>
                    <td class="center">{{ $log->checked_at?->format('d-m-Y') }}</td>
                    <td class="center">{{ $log->checked_at?->format('H:i') }}</td>
                    <td>{{ $log->product_name }}</td>
                    <td class="center">{{ number_format((float) $log->temperature_celsius, 2, ',', '.') }} °C</td>
                    <td>{{ $log->measured_name_snapshot ?: $log->measuredBy?->name ?: '-' }}</td>
                    <td></td>
                </tr>
            @endforeach
            @for($row = 0; $row < $fillerRows; $row++)
                <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td></tr>
            @endfor
        </tbody>
    </table>

    @if($logs->isNotEmpty())
        <div class="page-break"></div>
        <h2 class="documentation-title">DOKUMENTASI PEMANTAUAN SUHU</h2>
        <table>
            @foreach($logs->chunk(3) as $photos)
                <tr>
                    @foreach($photos as $log)
                        @php
                            $photoFile = $log->photo_path ? storage_path('app/public/'.$log->photo_path) : null;
                            $photoSource = $photoFile && is_file($photoFile)
                                ? 'data:'.(mime_content_type($photoFile) ?: 'image/jpeg').';base64,'.base64_encode(file_get_contents($photoFile))
                                : null;
                        @endphp
                        <td class="photo-card">
                            @if($photoSource)
                                <img class="photo" src="{{ $photoSource }}" alt="Dokumentasi suhu {{ $log->product_name }}">
                            @else
                                Foto tidak tersedia
                            @endif
                            <div class="caption">
                                <strong>{{ $log->product_name }}</strong><br>
                                {{ $log->checked_at?->format('d-m-Y H:i') }} ·
                                {{ number_format((float) $log->temperature_celsius, 2, ',', '.') }} °C ·
                                {{ $log->measured_name_snapshot ?: $log->measuredBy?->name ?: '-' }}
                            </div>
                        </td>
                    @endforeach
                    @for($empty = $photos->count(); $empty < 3; $empty++)
                        <td class="photo-card"></td>
                    @endfor
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
