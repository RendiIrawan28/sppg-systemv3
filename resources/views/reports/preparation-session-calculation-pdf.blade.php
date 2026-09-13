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
        .items .session-row td { height: 19px; background: #eef3f8; font-size: 8px; font-weight: bold; }
        .center { text-align: center; }
        .right { text-align: right; }
        .checkmark { text-align: center; font-size: 14px; font-weight: bold; }
        .signature { margin: 27px 0 0 42px; width: 70%; font-size: 10px; }
        .signature-title { font-weight: bold; }
        .signature-space { height: 53px; }
        .signature-name { line-height: 1.5; }
        .documentation-title { margin: 18px 0 7px; font-size: 11px; font-weight: bold; }
        .photo-grid { table-layout: fixed; }
        .photo-card { width: 33.33%; height: 145px; border: .7px solid #111; padding: 5px; text-align: center; vertical-align: top; }
        .photo { max-width: 100%; max-height: 104px; object-fit: contain; }
        .photo-caption { margin-top: 4px; font-size: 8px; }
    </style>
</head>
<body>
    @php
        $unit = $sessions->first()?->sppgUnit;
        $isMultiSession = $sessions->count() > 1;
        $itemCount = $sessions->sum(fn ($session) => $session->items->count());
        $fillerRows = max(0, 22 - $itemCount - ($isMultiSession ? $sessions->count() : 0));
        $rowNumber = 0;
        $petugasNames = $sessions->pluck('petugas.name')->filter()->unique()->values();
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
    <p class="date">Hari, Tanggal&nbsp;&nbsp;&nbsp;: {{ $reportDate?->translatedFormat('l, d F Y') }}</p>

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
            @foreach ($sessions as $session)
                @if ($isMultiSession)
                    <tr class="session-row">
                        <td colspan="8">{{ $session->session_number }} · {{ $session->purpose_reference ?: 'Persiapan' }} · Petugas: {{ $session->petugas?->name ?: '-' }}</td>
                    </tr>
                @endif
                @foreach ($session->items as $item)
                    @php
                        $rowNumber++;
                        $received = (float) ($item->received_quantity ?? $item->received_weight_kg);
                        $condition = strtolower((string) $item->condition_status);
                        $isGood = in_array($condition, ['good', 'accepted'], true);
                        $isDamaged = in_array($condition, ['damaged', 'rejected'], true);
                        $isModerate = in_array($condition, ['fair', 'moderate', 'medium'], true);
                    @endphp
                    <tr>
                        <td class="center">{{ $rowNumber }}</td>
                        <td>{{ $item->ingredient_name_snapshot }}</td>
                        <td class="center">{{ number_format($received, 3, ',', '.') }}</td>
                        <td class="center">{{ $item->unit_snapshot }}</td>
                        <td class="checkmark">{{ $isGood ? '✓' : '' }}</td>
                        <td class="checkmark">{{ $isDamaged ? '✓' : '' }}</td>
                        <td class="checkmark">{{ $isModerate ? '✓' : '' }}</td>
                        <td>{{ $item->notes ?: '-' }}</td>
                    </tr>
                @endforeach
            @endforeach
            @for ($row = 0; $row < $fillerRows; $row++)
                <tr>
                    <td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td>
                </tr>
            @endfor
        </tbody>
    </table>

    @php
        $documentedItems = $sessions->flatMap(fn ($session) => $session->items
            ->filter(fn ($item) => filled($item->resultDocumentation?->photo_path))
            ->map(fn ($item) => ['item' => $item, 'session' => $session]))->values();
    @endphp
    @if($documentedItems->isNotEmpty())
        <div class="documentation-title">DOKUMENTASI HASIL PERSIAPAN PER BAHAN</div>
        <table class="photo-grid">
            @foreach($documentedItems->chunk(3) as $photos)
                <tr>
                    @foreach($photos as $photo)
                        @php
                            $item = $photo['item'];
                            $photoFile = storage_path('app/public/'.$item->resultDocumentation->photo_path);
                            $photoSource = is_file($photoFile)
                                ? 'data:'.(mime_content_type($photoFile) ?: 'image/jpeg').';base64,'.base64_encode(file_get_contents($photoFile))
                                : null;
                        @endphp
                        <td class="photo-card">
                            @if($photoSource)
                                <img class="photo" src="{{ $photoSource }}" alt="Hasil Persiapan {{ $item->ingredient_name_snapshot }}">
                            @else
                                Foto tidak tersedia
                            @endif
                            <div class="photo-caption">
                                <strong>{{ $item->ingredient_name_snapshot }}</strong><br>
                                @if($isMultiSession){{ $photo['session']->session_number }}<br>@endif
                                Hasil siap: {{ number_format((float) $item->processed_quantity, 3, ',', '.') }} {{ $item->unit_snapshot }}
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

    <div class="signature">
        <div class="signature-title">Dihitung Oleh</div>
        <div class="signature-space"></div>
        <div class="signature-name">( {{ $petugasNames->isNotEmpty() ? $petugasNames->implode(', ') : '.................................' }} )</div>
    </div>
</body>
</html>
