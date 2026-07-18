<?php

return [
    /*
     * V3 berjalan sebagai instalasi single-unit. Jika tidak diisi, unit aktif
     * pertama menjadi unit sistem. Nilai ini menggantikan pemilih tenant.
     */
    'unit_id' => env('SPPG_UNIT_ID'),
];
