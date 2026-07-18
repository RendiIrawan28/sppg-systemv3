<?php

return [
    // Nilai awal tetap 5 hari, tetapi Ahli Gizi dapat memakai 1–60 hari.
    'default_cycle_length_days' => 5,
    'minimum_cycle_length_days' => 1,
    'maximum_cycle_length_days' => 60,

    // Buffer operasional dihitung terpisah untuk porsi kecil dan porsi besar.
    'default_buffer_percent' => 2,
    'minimum_buffer_percent' => 0,
    'maximum_buffer_percent' => 20,

    // Modul hanya menangani menu basah / makan utama.
    'meal_type' => 'lunch',

    // Komponen yang selalu wajib ada.
    'required_components' => [
        'staple',
        'animal_protein',
        'vegetable',
        'fruit',
    ],

    // Minimal salah satu harus tersedia. Keduanya boleh digunakan bersamaan.
    'at_least_one_component_groups' => [
        ['plant_protein', 'milk'],
    ],

    // Sayur boleh terdiri dari lebih dari satu hidangan/komponen.
    'multiple_components' => [
        'vegetable',
    ],

    'validated_nutrients' => [
        'energy',
        'protein',
        'fat',
        'carbohydrate',
        'fiber',
    ],

    'tolerance' => [
        'minimum_percent' => 90,
        'maximum_percent' => 110,
    ],

    // Tetap mengikuti profil gramasi yang sudah digunakan sistem saat ini.
    'audiences' => [
        'student' => 'Siswa, Guru, dan Tenaga Kependidikan',
        'toddler' => 'Balita',
        'maternal' => 'Ibu Hamil dan Ibu Menyusui',
    ],

    'portion_profiles' => [
        'small' => 'Porsi Kecil',
        'large' => 'Porsi Besar',
        'toddler' => 'Balita',
        'maternal' => 'Bumil/Busui',
    ],
];
