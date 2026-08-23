<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Lembar Monitoring Produksi</title>
    <style>
        @page { size: A4 landscape; margin: 28px 32px 34px; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111; font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        .title { margin: 0 0 13px; text-align: center; font-size: 15px; font-weight: normal; }
        .date { margin: 0 0 10px; font-size: 10px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { border: .7px solid #222; padding: 3px 4px; vertical-align: middle; }
        th { height: 28px; text-align: center; font-size: 9px; }
        td { height: 30px; }
        .center { text-align: center; vertical-align: middle; }
        .no { width: 5%; }
        .menu { width: 14%; }
        .material { width: 19%; }
        .qty { width: 8%; }
        .unit { width: 8%; }
        .duration { width: 16%; }
        .start { width: 12%; }
        .result { width: 11%; }
        .result-unit { width: 7%; }
        .footer { margin-top: 12px; width: 100%; }
        .signature { width: 32%; text-align: center; font-size: 9px; }
        .signature-space { height: 38px; }
        .summary { margin-top: 12px; }
        .summary td { height: auto; padding: 5px 7px; }
        .summary-label { width: 13%; font-weight: bold; background: #f1f5f9; }
        .documentation-title { margin: 14px 0 7px; font-size: 11px; font-weight: bold; }
        .photo-card { width: 33.33%; height: 150px; text-align: center; vertical-align: top; }
        .photo { max-width: 100%; max-height: 112px; object-fit: contain; }
        .caption { margin-top: 4px; font-size: 8px; }
    </style>
</head>
<body>
    @php
        $materials = $batch->materialUsages
            ->where('source_type', 'manual')
            ->map(fn ($item) => (object) [
                'material_name' => $item->material_name,
                'quantity' => $item->quantity,
                'unit_name' => $item->unit_name,
            ])
            ->values();
        $finishedOutputs = $batch->documentations
            ->where('documentation_type', 'finished_output')
            ->sortBy('sort_order')
            ->values();
        $rowCount = max(2, $materials->count(), $finishedOutputs->count());
        $fillerRows = max(0, 8 - $rowCount);
    @endphp

    <h1 class="title">LEMBAR MONITORING PRODUKSI</h1>
    <p class="date">Hari, Tanggal&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;: {{ $batch->production_date?->translatedFormat('l, d F Y') }}</p>

    <table>
        <thead>
            <tr>
                <th class="no">No</th>
                <th class="menu">Menu</th>
                <th class="material">Bahan Baku</th>
                <th class="qty">QTY</th>
                <th class="unit">Satuan</th>
                <th class="duration">Waktu Produksi</th>
                <th class="start">Jam Mulai</th>
                <th class="result">Hasil Akhir</th>
                <th class="result-unit">Satuan</th>
            </tr>
        </thead>
        <tbody>
            @for($index = 0; $index < $rowCount; $index++)
                @php
                    $material = $materials->get($index);
                    $finishedOutput = $finishedOutputs->get($index);
                @endphp
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td class="center">{{ $index === 0 ? $batch->menu_name_snapshot : '' }}</td>
                    <td class="center">{{ $material?->material_name ?: '' }}</td>
                    <td class="center">{{ $material ? number_format((float) $material->quantity, 3, ',', '.') : '' }}</td>
                    <td class="center">{{ $material?->unit_name ?: '' }}</td>
                    <td class="center">{{ $index === 0 && $batch->duration_minutes !== null ? $batch->duration_minutes.' menit' : '' }}</td>
                    <td class="center">{{ $index === 0 ? ($batch->started_at?->format('H:i') ?: '') : '' }}</td>
                    <td class="center">
                        @if($finishedOutput)
                            {{ number_format((float) $finishedOutput->output_quantity, 3, ',', '.') }}
                        @elseif($index === 0)
                            {{ number_format((float) $batch->actual_output_quantity, 3, ',', '.') }}
                        @endif
                    </td>
                    <td class="center">
                        {{ $finishedOutput?->output_unit ?: ($index === 0 ? $batch->actual_output_unit : '') }}
                    </td>
                </tr>
            @endfor
            @for($row = 0; $row < $fillerRows; $row++)
                <tr><td>&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
            @endfor
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td class="summary-label">Nomor Batch</td>
            <td>{{ $batch->batch_number }}</td>
            <td class="summary-label">Produk/Menu</td>
            <td>{{ $batch->product_name ?: $batch->menu_name_snapshot }}</td>
        </tr>
        <tr>
            <td class="summary-label">Jam Mulai</td>
            <td>{{ $batch->started_at?->format('d-m-Y H:i') ?: '-' }}</td>
            <td class="summary-label">Jam Selesai</td>
            <td>{{ $batch->completed_at?->format('d-m-Y H:i') ?: '-' }}</td>
        </tr>
        <tr>
            <td class="summary-label">Petugas</td>
            <td>{{ $batch->petugas_name_snapshot ?: $batch->petugas?->name ?: '-' }}</td>
            <td class="summary-label">Catatan</td>
            <td>{{ $batch->notes ?: '-' }}</td>
        </tr>
    </table>

    @if($finishedOutputs->isNotEmpty())
        <div class="documentation-title">DOKUMENTASI HASIL PRODUKSI</div>
        <table>
            @foreach($finishedOutputs->chunk(3) as $photos)
                <tr>
                    @foreach($photos as $photo)
                        @php
                            $photoFile = $photo->photo_path ? storage_path('app/public/'.$photo->photo_path) : null;
                            $photoSource = $photoFile && is_file($photoFile)
                                ? 'data:'.(mime_content_type($photoFile) ?: 'image/jpeg').';base64,'.base64_encode(file_get_contents($photoFile))
                                : null;
                        @endphp
                        <td class="photo-card">
                            @if($photoSource)
                                <img class="photo" src="{{ $photoSource }}" alt="Dokumentasi hasil produksi">
                            @else
                                Foto tidak tersedia
                            @endif
                            <div class="caption">
                                {{ number_format((float) $photo->output_quantity, 3, ',', '.') }} {{ $photo->output_unit }}
                                @if($photo->caption) — {{ $photo->caption }} @endif
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

    <table class="footer">
        <tr>
            <td style="border:0"></td>
            <td class="signature" style="border:0">
                Kepala Divisi Pengolahan
                <div class="signature-space"></div>
                ( {{ $batch->divisionApprover?->name ?: '.................................' }} )
            </td>
        </tr>
    </table>
</body>
</html>
