<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Berita Acara Serah Terima Limbah Pencucian</title>
    <style>
        @page { size: letter portrait; margin: 24px 32px 28px; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #000; line-height: 1.38; }
        table { width: 100%; border-collapse: collapse; }
        .document-header td { border: .8px solid #111; vertical-align: middle; }
        .brand { width: 13%; padding: 5px; text-align: center; font-size: 8px; font-weight: bold; }
        .brand img { width: 57px; height: 57px; object-fit: contain; }
        .heading { width: 39%; padding: 5px; text-align: center; font-size: 11px; font-weight: bold; line-height: 1.25; }
        .metadata { width: 48%; padding: 5px; font-size: 9px; }
        .metadata strong { display: inline-block; width: 88px; }
        .opening { margin: 17px 0 9px; }
        .party { width: 82%; margin-left: 8px; }
        .party td { border: 0; padding: 2px 0; }
        .party .label { width: 18%; }
        .party .separator { width: 3%; }
        .party-reference { margin: 4px 0 10px 8px; }
        .statement { margin: 10px 0 7px; }
        .waste th, .waste td { min-height: 34px; border: .8px solid #111; padding: 5px; vertical-align: middle; }
        .waste th { background: #d8e2f2; text-align: center; font-weight: bold; line-height: 1.15; }
        .number { width: 6%; text-align: center; }
        .type { width: 27%; }
        .quantity { width: 16%; }
        .handling { width: 25%; }
        .notes { width: 26%; }
        .right { text-align: right; }
        .closing { margin: 12px 0 13px; }
        .approval { margin: 0 0 8px; text-align: center; font-weight: bold; }
        .signatures td { height: 93px; border: .8px solid #111; padding: 5px; text-align: center; vertical-align: bottom; width: 50%; }
        .sign-role { font-weight: bold; }
        .page-number { position: fixed; right: 2px; bottom: -14px; font-size: 9px; }
        .page-number:after { content: counter(page); }
    </style>
</head>
<body>
    @php
        $unit = $session->sppgUnit;
        $items = $session->wasteRecords;
        $fillerRows = max(0, 3 - $items->count());
    @endphp

    <table class="document-header">
        <tr>
            <td class="brand" rowspan="3">
                <img src="{{ public_path('images/logo-bgn.png') }}" alt="Logo BGN"><br>
                Badan Gizi<br>Nasional
            </td>
            <td class="heading">{{ strtoupper($unit?->name ?: 'SPPG') }}</td>
            <td class="metadata"><strong>No. Dokumen</strong>: &nbsp;FR/PCN/20</td>
        </tr>
        <tr><td class="heading">FORM</td><td class="metadata"><strong>Revisi</strong>: &nbsp;00</td></tr>
        <tr><td class="heading">BERITA ACARA SERAH TERIMA<br>LIMBAH SISA MAKANAN</td><td class="metadata"><strong>Tanggal Berlaku</strong>: &nbsp;1 Januari 2026</td></tr>
    </table>

    <p class="opening">
        Kami yang bertanda tangan di bawah ini, pada hari {{ $session->washing_date?->translatedFormat('l') }},
        tanggal {{ $session->washing_date?->translatedFormat('d F Y') }}, untuk sesi Pencucian
        {{ $session->containerCollectionRun?->run_number ?: $session->distributionRun?->route_name ?: $session->session_number }}:
    </p>

    <table class="party">
        <tr><td class="label">Nama</td><td class="separator">:</td><td>{{ $session->waste_first_party_name }}</td></tr>
        <tr><td>Jabatan</td><td>:</td><td>{{ $session->waste_first_party_position }}</td></tr>
        <tr><td>Alamat</td><td>:</td><td>{{ $session->waste_first_party_address }}</td></tr>
    </table>
    <p class="party-reference">Selanjutnya disebut <strong>PIHAK PERTAMA</strong></p>

    <table class="party">
        <tr><td class="label">Nama</td><td class="separator">:</td><td>{{ $session->waste_second_party_name }}</td></tr>
        <tr><td>Jabatan</td><td>:</td><td>{{ $session->waste_second_party_position }}</td></tr>
        <tr><td>Alamat</td><td>:</td><td>{{ $session->waste_second_party_address }}</td></tr>
    </table>
    <p class="party-reference">Selanjutnya disebut <strong>PIHAK KEDUA</strong></p>

    <p class="statement">
        <strong>PIHAK PERTAMA</strong> menyerahkan limbah kepada <strong>PIHAK KEDUA</strong> dan
        <strong>PIHAK KEDUA</strong> menyatakan telah menerima limbah berupa daftar berikut:
    </p>

    <table class="waste">
        <thead><tr><th class="number">NO</th><th class="type">JENIS LIMBAH</th><th class="quantity">JUMLAH</th><th class="handling">PENANGANAN / PENERIMA</th><th class="notes">CATATAN</th></tr></thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td class="number">{{ $loop->iteration }}.</td>
                    <td>{{ $item->waste_type }}</td>
                    <td class="right">{{ number_format((float) $item->quantity, 3, ',', '.') }} {{ $item->unit }}</td>
                    <td>{{ $item->disposal_method }}<br>{{ $item->handed_over_to }}</td>
                    <td>{{ $item->notes ?: '-' }}</td>
                </tr>
            @endforeach
            @for ($row = 0; $row < $fillerRows; $row++)
                <tr><td class="number">{{ $items->count() + $row + 1 }}.</td><td>&nbsp;</td><td></td><td></td><td></td></tr>
            @endfor
        </tbody>
    </table>

    @if ($session->waste_handover_notes)
        <p><strong>Catatan:</strong> {{ $session->waste_handover_notes }}</p>
    @endif

    <p class="closing">
        Demikian berita acara serah-terima limbah ini dibuat. Sejak penandatanganan berita acara,
        limbah tersebut menjadi tanggung jawab <strong>PIHAK KEDUA</strong>.
    </p>

    <p class="approval">MENYETUJUI,</p>
    <table class="signatures">
        <tr>
            <td><div>( {{ $session->waste_first_party_name }} )</div><div class="sign-role">PIHAK PERTAMA</div></td>
            <td><div>( {{ $session->waste_second_party_name }} )</div><div class="sign-role">PIHAK KEDUA</div></td>
        </tr>
    </table>
    <div class="page-number"></div>
</body>
</html>
