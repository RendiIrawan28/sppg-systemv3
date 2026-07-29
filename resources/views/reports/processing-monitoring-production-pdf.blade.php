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
    </style>
</head>
<body>
    @php
        $materials = $batch->materialUsages
            ->map(fn ($item) => (object) [
                'material_name' => $item->material_name,
                'quantity' => $item->quantity,
                'unit_name' => $item->unit_name,
            ])
            ->concat(
                $batch->preparationOutputWithdrawals
                    ->where('status', 'verified')
                    ->map(fn ($withdrawal) => (object) [
                        'material_name' => $withdrawal->output?->output_name.' (hasil Persiapan)',
                        'quantity' => $withdrawal->verified_quantity,
                        'unit_name' => $withdrawal->unit_snapshot,
                    ]),
            )
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
