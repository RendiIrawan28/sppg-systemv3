<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">

    <title>Laporan Monitoring Produksi</title>

    <style>
        @page {
            size: A4 landscape;
            margin: 22px 26px 28px 26px;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #111;
            font-family: DejaVu Sans, sans-serif;
            font-size: 9px;
        }

        /* =========================================================
         * HEADER
         * ========================================================= */

        .header {
            width: 100%;
            text-align: center;
            margin-bottom: 14px;
        }

        .logo-wrapper {
            width: 100%;
            text-align: center;
            margin-bottom: 5px;
        }

        .logo {
            width: 58px;
            height: auto;
            display: inline-block;
        }

        .title {
            margin: 0;
            text-align: center;
            font-size: 16px;
            font-weight: bold;
        }

        .date {
            margin: 8px 0 0 0;
            text-align: center;
            font-size: 10px;
        }

        /* =========================================================
         * TABEL MONITORING
         * ========================================================= */

        .report-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .report-table th,
        .report-table td {
            border: 0.8px solid #222;
            padding: 5px 5px;
            vertical-align: middle;
        }

        .report-table th {
            height: 30px;
            text-align: center;
            font-size: 9px;
            font-weight: bold;
        }

        .report-table td {
            min-height: 27px;
        }

        .center {
            text-align: center;
        }

        .no {
            width: 4%;
        }

        .menu {
            width: 16%;
        }

        .material {
            width: 17%;
        }

        .qty {
            width: 7%;
        }

        .unit {
            width: 7%;
        }

        .duration {
            width: 11%;
        }

        .start {
            width: 8%;
        }

        .result {
            width: 12%;
        }

        .officer {
            width: 18%;
        }

        /* =========================================================
         * DOKUMENTASI
         * ========================================================= */

        .page-break {
            page-break-before: always;
        }

        .documentation-title {
            margin: 0 0 12px 0;
            text-align: center;
            font-size: 14px;
            font-weight: bold;
        }

        .photo-grid {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .photo-grid td {
            width: 33.33%;
            height: 190px;
            border: 0.8px solid #222;
            padding: 8px;
            text-align: center;
            vertical-align: top;
        }

        .photo-wrapper {
            width: 100%;
            height: 135px;
            text-align: center;
        }

        .photo {
            max-width: 100%;
            max-height: 130px;
        }

        .caption {
            margin-top: 6px;
            font-size: 8px;
            line-height: 1.45;
            text-align: center;
        }

        .caption-number {
            font-weight: bold;
        }

        .empty-message {
            text-align: center;
            padding: 12px;
        }
    </style>
</head>

<body>

    {{-- ============================================================
        HEADER
    ============================================================ --}}

    <div class="header">

        <div class="logo-wrapper">
            @php
                $logoPath = public_path('images/logo-bgn.png');
            @endphp

            @if(is_file($logoPath))
                <img
                    class="logo"
                    src="{{ $logoPath }}"
                    alt="Logo Badan Gizi Nasional"
                >
            @endif
        </div>

        <h1 class="title">
            LAPORAN MONITORING PRODUKSI
        </h1>

        <p class="date">
            Tanggal:
            {{ $reportDate?->format('d-m-Y') ?? '-' }}
        </p>

    </div>


    {{-- ============================================================
        TABEL MONITORING PRODUKSI
    ============================================================ --}}

    <table class="report-table">

        <thead>
            <tr>
                <th class="no">
                    No
                </th>

                <th class="menu">
                    Menu
                </th>

                <th class="material">
                    Bahan
                </th>

                <th class="qty">
                    Qty
                </th>

                <th class="unit">
                    Satuan
                </th>

                <th class="duration">
                    Waktu
                </th>

                <th class="start">
                    Mulai
                </th>

                <th class="result">
                    Hasil
                </th>

                <th class="officer">
                    Petugas
                </th>
            </tr>
        </thead>

        <tbody>

            @forelse($batches as $batchIndex => $batch)

                @php
                    /*
                     * Hanya bahan yang dicatat manual sebagai
                     * Monitoring Produksi.
                     */
                    $materials = $batch->materialUsages
                        ->where('source_type', 'manual')
                        ->values();

                    /*
                     * Bila tidak ada bahan, tetap buat satu baris
                     * supaya batch tetap muncul di laporan.
                     */
                    $rows = max(1, $materials->count());

                    /*
                     * Durasi produksi.
                     */
                    $duration = $batch->duration_minutes !== null
                        ? number_format(
                            (float) $batch->duration_minutes,
                            0,
                            ',',
                            '.'
                        ).' menit'
                        : '-';

                    /*
                     * Hasil produksi.
                     *
                     * Tidak menggunakan angka di belakang koma.
                     *
                     * Contoh:
                     * 50.000 -> 50
                     * 1000.000 -> 1.000
                     */
                    $resultQuantity = number_format(
                        (float) $batch->actual_output_quantity,
                        0,
                        ',',
                        '.'
                    );

                    $resultUnit = trim(
                        (string) ($batch->actual_output_unit ?: '')
                    );

                    $result = trim(
                        $resultQuantity.' '.$resultUnit
                    );

                    /*
                     * Petugas.
                     */
                    $officer = $batch->petugas_name_snapshot
                        ?: $batch->petugas?->name
                        ?: '-';

                    /*
                     * Nama menu / produk.
                     */
                    $menu = $batch->product_name
                        ?: $batch->menu_name_snapshot
                        ?: '-';
                @endphp


                @for(
                    $materialIndex = 0;
                    $materialIndex < $rows;
                    $materialIndex++
                )

                    @php
                        $material = $materials->get(
                            $materialIndex
                        );

                        /*
                         * Qty bahan tanpa angka di belakang koma.
                         */
                        $materialQuantity = $material
                            ? number_format(
                                (float) $material->quantity,
                                0,
                                ',',
                                '.'
                            )
                            : '-';

                        $materialName = $material?->material_name
                            ?: '-';

                        $materialUnit = $material?->unit_name
                            ?: '-';
                    @endphp


                    <tr>

                        {{-- NOMOR --}}
                        @if($materialIndex === 0)

                            <td
                                class="center"
                                rowspan="{{ $rows }}"
                            >
                                {{ $batchIndex + 1 }}
                            </td>

                        @endif


                        {{-- MENU --}}
                        @if($materialIndex === 0)

                            <td rowspan="{{ $rows }}">
                                {{ $menu }}
                            </td>

                        @endif


                        {{-- BAHAN --}}
                        <td>
                            {{ $materialName }}
                        </td>


                        {{-- QTY --}}
                        <td class="center">
                            {{ $materialQuantity }}
                        </td>


                        {{-- SATUAN --}}
                        <td class="center">
                            {{ $materialUnit }}
                        </td>


                        {{-- DATA PER BATCH --}}
                        @if($materialIndex === 0)

                            {{-- DURASI --}}
                            <td
                                class="center"
                                rowspan="{{ $rows }}"
                            >
                                {{ $duration }}
                            </td>


                            {{-- JAM MULAI --}}
                            <td
                                class="center"
                                rowspan="{{ $rows }}"
                            >
                                {{
                                    $batch->started_at
                                        ?->format('H:i')
                                        ?: '-'
                                }}
                            </td>


                            {{-- HASIL --}}
                            <td
                                class="center"
                                rowspan="{{ $rows }}"
                            >
                                {{ $result }}
                            </td>


                            {{-- PETUGAS --}}
                            <td rowspan="{{ $rows }}">
                                {{ $officer }}
                            </td>

                        @endif

                    </tr>

                @endfor

            @empty

                <tr>
                    <td
                        colspan="9"
                        class="empty-message"
                    >
                        Belum ada data Monitoring Produksi.
                    </td>
                </tr>

            @endforelse

        </tbody>

    </table>


    {{-- ============================================================
        AMBIL SATU DOKUMENTASI UTAMA PER BATCH
    ============================================================ --}}

    @php
        $documentations = $batches
            ->map(function ($batch) {

                $documentation = $batch
                    ->documentations
                    ->where(
                        'documentation_type',
                        'finished_output'
                    )
                    ->sortBy('sort_order')
                    ->first();

                if (! $documentation) {
                    return null;
                }

                return (object) [
                    'batch' => $batch,
                    'documentation' => $documentation,
                ];
            })
            ->filter()
            ->values();
    @endphp


    {{-- ============================================================
        HALAMAN DOKUMENTASI
    ============================================================ --}}

    @if($documentations->isNotEmpty())

        <div class="page-break"></div>

        <h2 class="documentation-title">
            DOKUMENTASI MONITORING PRODUKSI
        </h2>


        <table class="photo-grid">

            @foreach(
                $documentations->chunk(3)
                as $photos
            )

                <tr>

                    @foreach($photos as $entry)

                        @php
                            $photo = $entry->documentation;
                            $batch = $entry->batch;

                            /*
                             * Ambil file dokumentasi dari public disk.
                             */
                            $photoFile = $photo->photo_path
                                ? storage_path(
                                    'app/public/'.
                                    $photo->photo_path
                                )
                                : null;

                            /*
                             * Convert ke Base64 agar DomPDF lebih stabil.
                             */
                            $photoSource = null;

                            if (
                                $photoFile
                                && is_file($photoFile)
                            ) {
                                $mime = mime_content_type(
                                    $photoFile
                                ) ?: 'image/jpeg';

                                $photoSource =
                                    'data:'.
                                    $mime.
                                    ';base64,'.
                                    base64_encode(
                                        file_get_contents(
                                            $photoFile
                                        )
                                    );
                            }

                            /*
                             * Hasil tanpa decimal.
                             */
                            $documentationResult =
                                number_format(
                                    (float) $batch
                                        ->actual_output_quantity,
                                    0,
                                    ',',
                                    '.'
                                );

                            $documentationUnit =
                                trim(
                                    (string) (
                                        $batch
                                            ->actual_output_unit
                                        ?: ''
                                    )
                                );

                            $documentationOfficer =
                                $batch
                                    ->petugas_name_snapshot
                                ?: $batch
                                    ->petugas?->name
                                ?: '-';

                            $documentationMenu =
                                $batch->product_name
                                ?: $batch
                                    ->menu_name_snapshot
                                ?: '-';

                            $documentationTime =
                                $photo
                                    ->captured_at
                                    ?->format('H:i')
                                ?: $batch
                                    ->completed_at
                                    ?->format('H:i')
                                ?: '-';
                        @endphp


                        <td>

                            <div class="photo-wrapper">

                                @if($photoSource)

                                    <img
                                        class="photo"
                                        src="{{ $photoSource }}"
                                        alt="Dokumentasi {{ $documentationMenu }}"
                                    >

                                @else

                                    <div>
                                        Foto tidak tersedia
                                    </div>

                                @endif

                            </div>


                            <div class="caption">

                                <div class="caption-number">
                                    {{
                                        $batch->batch_number
                                            ?: '-'
                                    }}
                                </div>

                                <div>
                                    {{ $documentationMenu }}
                                </div>

                                <div>
                                    {{
                                        $documentationResult
                                    }}
                                    {{
                                        $documentationUnit
                                    }}
                                    ·
                                    {{
                                        $documentationTime
                                    }}
                                    ·
                                    {{
                                        $documentationOfficer
                                    }}
                                </div>

                            </div>

                        </td>

                    @endforeach


                    {{--
                        Jika row terakhir kurang dari 3 foto,
                        tambahkan cell kosong.
                    --}}
                    @for(
                        $empty = $photos->count();
                        $empty < 3;
                        $empty++
                    )

                        <td></td>

                    @endfor

                </tr>

            @endforeach

        </table>

    @endif

</body>
</html>