<!doctype html>
<html lang="id">

<head>

    <meta charset="utf-8">

    <style>
        /*
        |--------------------------------------------------------------------------
        | PAGE
        |--------------------------------------------------------------------------
        |
        | Jangan gunakan margin 0.
        |
        | Nilai ini mendekati contoh:
        | - tabel tidak menempel tepi
        | - garis header tidak menyentuh sisi kertas
        |
        */

        @page {
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
        }

        body {
            font-family:
                DejaVu Sans,
                sans-serif;

            color: #111111;

            font-size: 10px;

            line-height: 1.35;
        }

        .page-one-spacer {
            height: 40pt;
        }


        /*
        |--------------------------------------------------------------------------
        | HEADER
        |--------------------------------------------------------------------------
        */

        .header-table {
            width: 70%;
            margin-left: 15%;
            margin-right: 15%;
            border-collapse: collapse;
            table-layout: fixed;
            page-break-inside: avoid;
        }

        .header-table td {
            border: none;

            padding: 0;

            vertical-align: middle;
        }

        .header-logo {
            width: 20%;

            text-align: center;

            padding-right: 12px !important;
        }

        .header-logo img {
            width: 112px;

            height: auto;
        }

        .header-identity {
            width: 80%;

            text-align: left;
        }

        .header-agency {
            color: #253e6b;

            font-size: 18.5px;

            font-weight: bold;

            line-height: 1.15;
        }

        .header-agency-en {
            font-size: 17.3px;

            font-weight: normal;

            font-style: italic;
        }

        .header-unit {
            color: #253e6b;

            font-size: 17.3px;

            font-weight: bold;

            line-height: 1.25;

            margin-top: 3px;
        }

        .header-address {
            color: #111111;

            font-size: 14.7px;

            line-height: 1.3;

            margin-top: 3px;
        }


        /*
        |--------------------------------------------------------------------------
        | DOUBLE LINE
        |--------------------------------------------------------------------------
        */

        .header-divider {
            width: 84%;
            margin-left: 8%;
            margin-right: 8%;
            height: 5px;
            border-top: 2px solid #26364c;
            border-bottom: 1px solid #26364c;
            margin-top: 13px;
        }


        /*
        |--------------------------------------------------------------------------
        | DOCUMENT TITLE
        |--------------------------------------------------------------------------
        */

        .document-title {
            text-align: center;
            margin-top: 11px;
            margin-bottom: 42px;
        }

        .document-title-main {
            font-size: 18.5px;

            font-weight: bold;

            line-height: 1.2;
        }

        .document-title-unit {
            margin-top: 8px;

            font-size: 17.3px;

            font-weight: bold;

            line-height: 1.2;
        }


        /*
        |--------------------------------------------------------------------------
        | MENU TABLE
        |--------------------------------------------------------------------------
        */

        .menu-table {
            width: 84%;
            margin-left: 8%;
            margin-right: 8%;

            border-collapse: collapse;
            table-layout: fixed;
        }

        .menu-table th,
        .menu-table td {
            border:
                0.8px solid #222222;

            text-align: center;

            vertical-align: middle;
        }


        /*
        |--------------------------------------------------------------------------
        | HEADER TABLE MENU
        |--------------------------------------------------------------------------
        */

        .menu-table th {
            background-color: #d9e3f2;
            height: 38px;
            padding: 4px 3px 5px;
        }

        .day-name {
            font-size: 16px;

            font-weight: bold;

            line-height: 1.2;
        }

        .day-date {
            margin-top: 5px;

            font-size: 14.7px;

            font-weight: bold;

            line-height: 1.2;
        }


        /*
        |--------------------------------------------------------------------------
        | MENU CONTENT
        |--------------------------------------------------------------------------
        */

        .menu-cell {
            padding: 0 !important;
        }

        .menu-row {
            min-height: 28px;
            padding: 6px 5px 5px;
            font-size: 14.7px;
            line-height: 1.25;
        }

        .menu-row+.menu-row {
            border-top:
                0.8px solid #222222;
        }


        /*
        |--------------------------------------------------------------------------
        | HOLIDAY
        |--------------------------------------------------------------------------
        */

        .holiday-cell {
            padding:
                12px 8px !important;

            font-size: 14.7px;

            line-height: 1.55;

            vertical-align: middle !important;
        }

        .empty-cell {
            color: #666666;

            font-style: italic;
        }


        /*
        |--------------------------------------------------------------------------
        | PAGE BREAK
        |--------------------------------------------------------------------------
        */

        .page-break {
            page-break-before: always;
        }


        /*
        |--------------------------------------------------------------------------
        | NOTES
        |--------------------------------------------------------------------------
        */

        .notes-wrapper {
            width: 82%;
            margin: 14px 9% 0;
        }

        .notes-heading {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
        }

        .notes-title {
            padding-left: 4px;
            font-size: 17.3px;
            font-weight: bold;
            line-height: 1.2;
        }

        .notes-list {
            width: 100%;
            margin: 8px 0 0;

            padding-left: 36px;

            font-size: 16px;

            line-height: 1.65;
        }

        .notes-list li {
            margin-bottom: 5px;

            padding-left: 2px;
        }
    </style>

</head>


<body>


    @php

    use App\Enums\MenuComponentType;
    use Carbon\Carbon;


    /*
    |--------------------------------------------------------------------------
    | NAMA HARI
    |--------------------------------------------------------------------------
    */

    $dayNames = [
    1 => 'Senin',
    2 => 'Selasa',
    3 => 'Rabu',
    4 => 'Kamis',
    5 => 'Jumat',
    6 => 'Sabtu',
    7 => 'Minggu',
    ];


    /*
    |--------------------------------------------------------------------------
    | NAMA BULAN
    |--------------------------------------------------------------------------
    */

    $monthNames = [
    1 => 'Januari',
    2 => 'Februari',
    3 => 'Maret',
    4 => 'April',
    5 => 'Mei',
    6 => 'Juni',
    7 => 'Juli',
    8 => 'Agustus',
    9 => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember',
    ];


    /*
    |--------------------------------------------------------------------------
    | FORMAT HARI
    |--------------------------------------------------------------------------
    */

    $formatDay = function ($date) use (
    $dayNames
    ) {

    if (! $date) {
    return '-';
    }

    $date = Carbon::parse($date);

    return $dayNames[
    $date->dayOfWeekIso
    ] ?? '-';

    };


    /*
    |--------------------------------------------------------------------------
    | FORMAT TANGGAL
    |--------------------------------------------------------------------------
    */

    $formatDate = function ($date) use (
    $monthNames
    ) {

    if (! $date) {
    return '-';
    }

    $date = Carbon::parse($date);

    return sprintf(
    '%d %s %d',
    $date->day,
    $monthNames[$date->month],
    $date->year
    );

    };


    /*
    |--------------------------------------------------------------------------
    | IDENTITAS SPPG
    |--------------------------------------------------------------------------
    */

    $rawUnitName = trim(
    (string) (
    $cycle->sppgUnit?->name
    ?? 'SPPG'
    )
    );


    /*
    * Untuk judul:
    *
    * SPPG NOGOTIRTO
    */
    if (
    str_starts_with(
    strtoupper($rawUnitName),
    'SPPG'
    )
    ) {

    $documentUnitName =
    strtoupper(
    $rawUnitName
    );

    } else {

    $documentUnitName =
    'SPPG '
    .strtoupper(
    $rawUnitName
    );

    }


    /*
    * Untuk kop:
    *
    * SATUAN PELAYANAN PEMENUHAN GIZI
    * NOGOTIRTO
    *
    * Kata SPPG tidak ditampilkan dua kali.
    */
    $headerUnitName = preg_replace(
    '/^SPPG\s+/i',
    '',
    $rawUnitName
    );

    $headerUnitName =
    strtoupper(
    trim(
    (string)
    $headerUnitName
    )
    );


    /*
    |--------------------------------------------------------------------------
    | LOGO
    |--------------------------------------------------------------------------
    */

    $logoPath =
    public_path(
    'images/logo-bgn.png'
    );


    /*
    |--------------------------------------------------------------------------
    | URUTAN KOMPONEN
    |--------------------------------------------------------------------------
    |
    | Urutan sesuai contoh:
    |
    | 1. Karbohidrat / makanan pokok
    | 2. Protein hewani
    | 3. Protein nabati
    | 4. Sayur
    | 5. Buah
    | 6. Susu jika ada
    |
    */

    $componentOrder = [

    MenuComponentType::Staple
    ->value,

    MenuComponentType::AnimalProtein
    ->value,

    MenuComponentType::PlantProtein
    ->value,

    MenuComponentType::Vegetable
    ->value,

    MenuComponentType::Fruit
    ->value,

    MenuComponentType::Milk
    ->value,

    ];


    /*
    |--------------------------------------------------------------------------
    | BUILD MENU ROWS
    |--------------------------------------------------------------------------
    */

    $buildMenuRows =
    function ($menu)
    use ($componentOrder) {

    if (! $menu) {
    return collect();
    }

    return collect(
    $componentOrder
    )
    ->map(
    function (
    $componentType
    ) use ($menu) {

    $items =
    $menu->items
    ->where(
    'item_type',
    $componentType
    )
    ->sortBy(
    'sort_order'
    )
    ->pluck(
    'name'
    )
    ->filter()
    ->map(
    fn ($name) =>
    trim(
    (string)
    $name
    )
    )
    ->filter()
    ->unique()
    ->values();


    if (
    $items->isEmpty()
    ) {
    return null;
    }


    /*
    * Jika satu komponen berisi
    * lebih dari satu item:
    *
    * Brokoli / Wortel
    */
    return $items
    ->implode(' / ');

    }
    )
    ->filter()
    ->values();

    };


    /*
    |--------------------------------------------------------------------------
    | MAX ROW
    |--------------------------------------------------------------------------
    |
    | Menjaga tinggi semua hari sama.
    |
    */

    $maximumRows = 1;


    foreach (
    $cycle->days
    as $cycleDay
    ) {

    /*
    * Hari libur tidak dipakai
    * menentukan tinggi menu.
    */
    $dateKey =
    $cycleDay->service_date
    ? $cycleDay
    ->service_date
    ->format('Y-m-d')
    : null;


    if (
    $dateKey
    && $holidays->has(
    $dateKey
    )
    ) {
    continue;
    }


    $rows =
    $buildMenuRows(
    $cycleDay->menu
    );


    $maximumRows =
    max(
    $maximumRows,
    $rows->count()
    );

    }

    @endphp



    {{-- =========================================================
     HALAMAN 1
========================================================= --}}


    <div class="page-one-spacer"></div>

    <table class="header-table">

        <tr>


            {{-- LOGO --}}
            <td class="header-logo">

                @if (
                file_exists(
                $logoPath
                )
                )

                <img
                    src="{{ $logoPath }}"
                    alt="Logo Badan Gizi Nasional">

                @endif

            </td>



            {{-- IDENTITAS --}}
            <td class="header-identity">

                <div class="header-agency">

                    BADAN GIZI NASIONAL

                    <span
                        class="header-agency-en">
                        (NATIONAL NUTRITION AGENCY)
                    </span>

                </div>


                <div class="header-unit">

                    SATUAN PELAYANAN PEMENUHAN GIZI
                    {{ $headerUnitName }}

                </div>


                @if (
                $cycle->sppgUnit?->address
                )

                <div class="header-address">

                    {{ $cycle->sppgUnit->address }}

                </div>

                @endif

            </td>


        </tr>

    </table>



    <div class="header-divider"></div>



    <div class="document-title">


        <div class="document-title-main">

            SIKLUS MENU MAKAN BERGIZI GRATIS (MBG)

        </div>


        <div class="document-title-unit">

            {{ $documentUnitName }}

        </div>


    </div>



    <table class="menu-table">


        {{-- =========================
         HEADER HARI
    ========================== --}}

        <thead>

            <tr>


                @foreach (
                $cycle->days
                as $day
                )

                <th>


                    <div class="day-name">

                        {{ $formatDay(
                            $day->service_date
                        ) }},

                    </div>


                    <div class="day-date">

                        {{ $formatDate(
                            $day->service_date
                        ) }}

                    </div>


                </th>

                @endforeach


            </tr>

        </thead>



        {{-- =========================
         ISI MENU
    ========================== --}}

        <tbody>

            <tr>


                @foreach (
                $cycle->days
                as $day
                )


                @php

                $dateKey =
                $day->service_date
                ? Carbon::parse(
                $day->service_date
                )->format(
                'Y-m-d'
                )
                : null;


                $holiday =
                $dateKey
                ? $holidays->get(
                $dateKey
                )
                : null;


                $menuRows =
                $buildMenuRows(
                $day->menu
                );

                @endphp



                {{-- =========================
                     HARI LIBUR
                ========================== --}}

                @if ($holiday)


                <td class="holiday-cell">

                    Libur

                    <br>

                    {{ $holiday->name }}

                </td>



                {{-- =========================
                     BELUM ADA MENU
                ========================== --}}

                @elseif (! $day->menu)


                <td
                    class="
                            holiday-cell
                            empty-cell
                        ">

                    Menu belum tersedia

                </td>



                {{-- =========================
                     MENU PELAYANAN
                ========================== --}}

                @else


                <td class="menu-cell">


                    @foreach (
                    $menuRows
                    as $row
                    )


                    <div class="menu-row">

                        {{ $row }}

                    </div>


                    @endforeach



                    {{-- Tinggi kolom disamakan --}}
                    @for (
                    $i =
                    $menuRows->count();

                    $i <
                        $maximumRows;

                        $i++
                        )


                        <div class="menu-row">

                        &nbsp;

                        </div>


                        @endfor


                </td>


                @endif


                @endforeach


            </tr>

        </tbody>


    </table>




    {{-- =========================================================
     HALAMAN 2
========================================================= --}}


    <div class="page-break"></div>

    <div class="page-one-spacer"></div>

    <table class="header-table">
        <tr>
            <td class="header-logo">
                @if (file_exists($logoPath))
                    <img src="{{ $logoPath }}" alt="Logo Badan Gizi Nasional">
                @endif
            </td>

            <td class="header-identity">
                <div class="header-agency">
                    BADAN GIZI NASIONAL
                    <span class="header-agency-en">(NATIONAL NUTRITION AGENCY)</span>
                </div>

                <div class="header-unit">
                    SATUAN PELAYANAN PEMENUHAN GIZI {{ $headerUnitName }}
                </div>

                @if ($cycle->sppgUnit?->address)
                    <div class="header-address">{{ $cycle->sppgUnit->address }}</div>
                @endif
            </td>
        </tr>
    </table>

    <div class="header-divider"></div>



    <div class="notes-wrapper">


        <table class="notes-heading">
            <tr>
                <td class="notes-title">Note:</td>
            </tr>
        </table>



        <ul class="notes-list">


            <li>

                Menu mungkin dapat berubah jika terdapat
                bahan makanan yang tidak tersedia.

            </li>


            <li>

                Jika terdapat siswa yang alergi terhadap
                salah satu bahan makanan, mohon untuk
                diinformasikan max. 3 hari sebelum jadwal
                makanan disajikan.

            </li>


            <li>

                Jika terdapat kegiatan diluar sekolah atau
                pembelajaran daring, mohon untuk
                diinformasikan max. 2 hari sebelum
                (dan menyerahkan box nasi disposable ke
                pihak SPPG jika menghendaki menggunakan
                box nasi).

            </li>


        </ul>


    </div>


</body>

</html>
