<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Berita Acara Serah Terima Limbah Sisa Makanan</title>
    <style>
        @page { margin: 22px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        table { width: 100%; border-collapse: collapse; }
        .doc td { border: 1px solid #333; padding: 8px; vertical-align: middle; }
        .doc .left { width: 14%; text-align: center; font-size: 9px; }
        .doc .title { width: 40%; text-align: center; font-weight: bold; font-size: 15px; }
        .doc .meta { width: 46%; font-size: 11px; }
        .body-text { margin: 16px 0 8px; line-height: 1.35; }
        .party td { padding: 3px 0; }
        .items th, .items td { border: 1px solid #333; padding: 8px 6px; vertical-align: middle; }
        .items th { background: #dbe4f3; text-align: center; font-weight: bold; }
        .center { text-align: center; }
        .right { text-align: right; }
        .signature td { border: 1px solid #333; text-align: center; padding: 8px; width: 50%; }
        .sign-space { height: 58px; }
        .page-break { page-break-before: always; }
    </style>
</head>
<body>
    <table class="doc">
        <tr>
            <td class="left" rowspan="3">Badan Gizi<br>Nasional</td>
            <td class="title">SPPG SLEMAN GAMPING<br><br>NOGOTIRTO</td>
            <td class="meta"><strong>No. Dokumen</strong> &nbsp;&nbsp;: FR/PM/20</td>
        </tr>
        <tr>
            <td class="title">FORM</td>
            <td class="meta"><strong>Revisi</strong> &nbsp;&nbsp;: 00</td>
        </tr>
        <tr>
            <td class="title">BERITA ACARA SERAH TERIMA<br>LIMBAH SISA MAKANAN</td>
            <td class="meta"><strong>Tanggal Berlaku</strong> &nbsp;&nbsp;: 1 Januari 2026</td>
        </tr>
    </table>

    <p class="body-text">
        Kami yang bertanda tangan dibawah ini, pada hari ini {{ $handover->handover_date?->translatedFormat('l') }},
        tanggal {{ $handover->handover_date?->translatedFormat('d F Y') }}:
    </p>

    <table class="party">
        <tr><td style="width: 16%">Nama</td><td style="width: 3%">:</td><td>{{ $handover->preparation_officer_name ?: 'Mulyadi' }}</td></tr>
        <tr><td>Jabatan</td><td>:</td><td>Tim Persiapan</td></tr>
        <tr><td>Alamat</td><td>:</td><td>SPPG Nogotirto, Gamping, Sleman</td></tr>
    </table>
    <p>Selanjutnya disebut <strong>PIHAK PERTAMA</strong></p>

    <table class="party">
        <tr><td style="width: 16%">Nama</td><td style="width: 3%">:</td><td>.............................................................</td></tr>
        <tr><td>Jabatan</td><td>:</td><td>.............................................................</td></tr>
        <tr><td>Alamat</td><td>:</td><td>.............................................................</td></tr>
    </table>
    <p>Selanjutnya disebut <strong>PIHAK KEDUA</strong></p>

    <p class="body-text">
        <strong>PIHAK PERTAMA</strong> menyerahkan limbah kepada <strong>PIHAK KEDUA</strong> dan
        <strong>PIHAK KEDUA</strong> menyatakan telah menerima limbah dari <strong>PIHAK PERTAMA</strong>
        berupa daftar terlampir:
    </p>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 6%">NO</th>
                <th>JENIS LIMBAH</th>
                <th style="width: 26%">BERAT<br><span style="font-weight: normal">(dalam satuan koli atau bobot kg)</span></th>
                <th style="width: 28%">CATATAN</th>
            </tr>
        </thead>
        <tbody>
            @php
                $wasteItems = $handover->items->filter(fn($item) => (float) $item->waste_quantity > 0 || filled($item->waste_type));
            @endphp
            @foreach ($wasteItems as $item)
                <tr>
                    <td class="center">{{ $loop->iteration }}.</td>
                    <td>{{ $item->waste_type ?: ('Sisa '.$item->ingredient_name_snapshot) }}</td>
                    <td class="right">{{ number_format((float) $item->waste_quantity, 2, ',', '.') }} {{ $item->waste_unit_snapshot ?: $item->unit_snapshot }}</td>
                    <td>{{ $item->waste_notes ?: '-' }}</td>
                </tr>
            @endforeach
            @for ($i = $wasteItems->count(); $i < 3; $i++)
                <tr><td class="center">{{ $i + 1 }}.</td><td>&nbsp;</td><td></td><td></td></tr>
            @endfor
        </tbody>
    </table>

    <p class="body-text">
        Demikianlah berita acara serah terima limbah ini dibuat oleh kedua belah pihak.
        Sejak penandatanganan berita acara ini, limbah tersebut menjadi tanggung jawab <strong>PIHAK KEDUA</strong>.
    </p>

    <p style="text-align: center; font-weight: bold; margin-top: 22px;">MENYETUJUI,</p>
    <table class="signature">
        <tr><td class="sign-space"></td><td></td></tr>
    </table>

    <div class="page-break"></div>

    <table class="doc">
        <tr>
            <td class="left" rowspan="3">Badan Gizi<br>Nasional</td>
            <td class="title">SPPG SLEMAN GAMPING<br><br>NOGOTIRTO</td>
            <td class="meta"><strong>No. Dokumen</strong> &nbsp;&nbsp;: FR/PM/20</td>
        </tr>
        <tr>
            <td class="title">FORM</td>
            <td class="meta"><strong>Revisi</strong> &nbsp;&nbsp;: 00</td>
        </tr>
        <tr>
            <td class="title">BERITA ACARA SERAH TERIMA<br>LIMBAH SISA MAKANAN</td>
            <td class="meta"><strong>Tanggal Berlaku</strong> &nbsp;&nbsp;: 1 Januari 2026</td>
        </tr>
    </table>

    <table class="signature" style="margin-top: 28px;">
        <tr>
            <td>
                <div style="height: 44px;">(.............................................................)</div>
                <strong>PIHAK PERTAMA</strong>
            </td>
            <td>
                <div style="height: 44px;">(.............................................................)</div>
                <strong>PIHAK KEDUA</strong>
            </td>
        </tr>
    </table>
</body>
</html>
