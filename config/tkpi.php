<?php

return [
    /*
    | Dataset lokal dipakai sebagai snapshot terkontrol. Sistem tidak melakukan
    | scraping langsung ke situs TKPI/Panganku saat aplikasi berjalan.
    */
    'csv_path' => database_path('data/tkpi_2017.csv'),

    /*
    | TKPI memiliki baris BDD kosong. Karena kalkulator menu membutuhkan angka,
    | nilai berikut dipakai sebagai fallback operasional dan dihitung dalam
    | statistik import. Ubah ke null untuk melewati bahan yang BDD-nya kosong.
    */
    'missing_bdd_fallback' => 100.0,

    'ingredient_code_prefix' => '',

    /* ID/kode unit untuk TkpiFoodSeeder. Kosong berarti semua unit aktif. */
    'seed_units' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TKPI_SEED_UNIT_IDS', ''))
    ))),
];
