<?php

return [
    // Tolak master tanpa kolom Unit SPPG agar data tenant lain tidak ikut terbaca.
    'require_unit_scope' => true,

    /*
     * Model tujuan yang sudah tersedia di project utama.
     * Nilai ini dapat diganti jika nama class pada project berbeda.
     */
    'destination_models' => [
        'school' => \App\Models\School::class,
        'posyandu' => \App\Models\Posyandu::class,
    ],

    /*
     * Kandidat nama kolom. Service akan memakai kolom pertama yang tersedia.
     * Ini menjaga kompatibilitas dengan struktur master yang sudah terpasang.
     */
    'destination_columns' => [
        'unit' => ['sppg_unit_id', 'unit_id'],
        'name' => ['name', 'nama', 'school_name', 'posyandu_name', 'nama_sekolah', 'nama_posyandu'],
        'code' => ['code', 'school_code', 'posyandu_code', 'kode_sekolah', 'kode_posyandu'],
        'address' => ['address', 'alamat'],
        'contact_name' => ['pic_name', 'contact_name', 'nama_pic', 'kader_name', 'head_name'],
        'contact_phone' => ['pic_phone', 'contact_phone', 'phone', 'no_hp', 'telephone'],
        'route' => ['route_name', 'distribution_route', 'route', 'rute_distribusi'],
        'latitude' => ['latitude', 'lat'],
        'longitude' => ['longitude', 'lng', 'lon'],
        'active' => ['is_active', 'active', 'status_aktif', 'status_menerima', 'is_receiving'],
        'beneficiary_count' => [
            'active_beneficiaries_count',
            'beneficiary_count',
            'jumlah_penerima_aktif',
            'active_recipient_count',
            'total_beneficiaries',
            'jumlah_penerima',
            'jumlah_penerima_manfaat',
        ],
    ],

    'beneficiary_model' => \App\Models\Beneficiary::class,
    'beneficiary_columns' => [
        'unit' => ['sppg_unit_id', 'unit_id'],
        'school_foreign_key' => ['school_id', 'sekolah_id'],
        'posyandu_foreign_key' => ['posyandu_id'],
        'active' => ['is_active', 'active', 'status_aktif', 'status_menerima', 'is_receiving'],
    ],
];
