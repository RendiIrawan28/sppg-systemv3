<?php

namespace App\Support\V3;

use App\Models\Allergen;
use App\Models\BeneficiaryCategory;
use App\Models\CleaningArea;
use App\Models\Division;
use App\Models\Ingredient;
use App\Models\MeasurementUnit;
use App\Models\NutritionComponent;
use App\Models\NutritionStandard;
use App\Models\IngredientPortionStandard;
use App\Models\Posyandu;
use App\Models\School;
use App\Models\ServiceHoliday;
use App\Models\Supplier;
use Illuminate\Support\Str;

final class MasterDataRegistry
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return [
            'schools' => $this->catalog('Sekolah', 'Institusi penerima manfaat kategori sekolah.', School::class, 'schools', true, ['code', 'name', 'education_level', 'pic_name'], [
                'code' => $this->field('Kode sekolah', 'text', ['required', 'string', 'max:50'], unique: true),
                'npsn' => $this->field('NPSN', 'text', ['nullable', 'string', 'max:20'], unique: true),
                'name' => $this->field('Nama sekolah', 'text', ['required', 'string', 'max:255']),
                'education_level' => $this->field('Jenjang', 'select', ['required', 'string'], options: ['paud' => 'PAUD', 'tk' => 'TK', 'sd' => 'SD', 'smp' => 'SMP', 'sma' => 'SMA', 'smk' => 'SMK', 'slb' => 'SLB', 'other' => 'Lainnya']),
                'address' => $this->field('Alamat lengkap', 'textarea', ['nullable', 'string', 'max:2000']),
                'village' => $this->field('Desa/Kelurahan'),
                'district' => $this->field('Kecamatan'),
                'city' => $this->field('Kabupaten/Kota'),
                'province' => $this->field('Provinsi'),
                'pic_name' => $this->field('Nama PIC'),
                'pic_phone' => $this->field('Telepon PIC', 'tel'),
                'pic_email' => $this->field('Email PIC', 'email', ['nullable', 'email', 'max:255']),
                'latitude' => $this->field('Latitude', 'number', ['nullable', 'numeric', 'between:-90,90']),
                'longitude' => $this->field('Longitude', 'number', ['nullable', 'numeric', 'between:-180,180']),
                'receiving_time' => $this->field('Jam penerimaan', 'time'),
                'notes' => $this->field('Catatan', 'textarea', ['nullable', 'string', 'max:2000']),
                'is_active' => $this->toggle(),
            ]),
            'posyandus' => $this->catalog('Posyandu', 'Institusi penerima balita dan kelompok maternal.', Posyandu::class, 'posyandus', true, ['code', 'name', 'service_area', 'pic_name'], [
                'code' => $this->field('Kode posyandu', rules: ['required', 'string', 'max:50'], unique: true),
                'name' => $this->field('Nama posyandu', rules: ['required', 'string', 'max:255']),
                'service_area' => $this->field('Wilayah pelayanan'),
                'address' => $this->field('Alamat lengkap', 'textarea', ['nullable', 'string', 'max:2000']),
                'village' => $this->field('Desa/Kelurahan'),
                'district' => $this->field('Kecamatan'),
                'city' => $this->field('Kabupaten/Kota'),
                'province' => $this->field('Provinsi'),
                'pic_name' => $this->field('Nama kader/PIC'),
                'pic_phone' => $this->field('Telepon PIC', 'tel'),
                'pic_email' => $this->field('Email PIC', 'email', ['nullable', 'email', 'max:255']),
                'latitude' => $this->field('Latitude', 'number', ['nullable', 'numeric', 'between:-90,90']),
                'longitude' => $this->field('Longitude', 'number', ['nullable', 'numeric', 'between:-180,180']),
                'receiving_time' => $this->field('Jam penerimaan', 'time'),
                'notes' => $this->field('Catatan', 'textarea', ['nullable', 'string', 'max:2000']),
                'is_active' => $this->toggle(),
            ]),
            'beneficiary-categories' => $this->catalog('Kategori Penerima', 'Kelompok penerima, urutan, dan ukuran porsinya.', BeneficiaryCategory::class, 'beneficiaries', true, ['code', 'name', 'sort_order'], [
                'code' => $this->field('Kode', rules: ['required', 'string', 'max:50'], unique: true, normalize: 'slug'),
                'name' => $this->field('Nama kategori', rules: ['required', 'string', 'max:255']),
                'group_type' => $this->field('Kelompok', 'select', ['nullable', 'string'], options: ['school' => 'Sekolah', 'toddler' => 'Balita', 'pregnant' => 'Ibu Hamil', 'breastfeeding' => 'Ibu Menyusui', 'other' => 'Lainnya']),
                'education_level' => $this->field('Jenjang'),
                'grade_start' => $this->field('Kelas awal', 'number', ['nullable', 'integer', 'min:0']),
                'grade_end' => $this->field('Kelas akhir', 'number', ['nullable', 'integer', 'min:0']),
                'portion_size' => $this->field('Profil porsi'),
                'menu_audience' => $this->field('Audiens menu'),
                'sort_order' => $this->field('Urutan', 'number', ['required', 'integer', 'min:0'], default: 0),
                'description' => $this->field('Deskripsi', 'textarea', ['nullable', 'string', 'max:2000']),
                'is_active' => $this->toggle(),
            ]),
            'measurement-units' => $this->catalog('Satuan Bahan', 'Satuan dan faktor konversi resep maupun stok.', MeasurementUnit::class, 'measurement_units', false, ['code', 'name', 'symbol', 'unit_type'], [
                'code' => $this->field('Kode satuan', rules: ['required', 'string', 'max:30'], unique: true, normalize: 'lower'),
                'name' => $this->field('Nama satuan', rules: ['required', 'string', 'max:255']),
                'symbol' => $this->field('Simbol', rules: ['required', 'string', 'max:20']),
                'unit_type' => $this->field('Jenis satuan', 'select', ['required', 'string'], options: ['weight' => 'Berat', 'volume' => 'Volume', 'count' => 'Jumlah/Satuan']),
                'to_base_factor' => $this->field('Faktor konversi', 'number', ['required', 'numeric', 'gt:0'], default: 1),
                'is_active' => $this->toggle(),
            ]),
            'nutrition-components' => $this->catalog('Komponen Gizi', 'Komponen nilai gizi yang dipakai seluruh perhitungan.', NutritionComponent::class, 'nutrition', false, ['code', 'name', 'unit', 'sort_order'], [
                'code' => $this->field('Kode komponen', rules: ['required', 'string', 'max:50'], unique: true, normalize: 'slug'),
                'name' => $this->field('Nama komponen', rules: ['required', 'string', 'max:255']),
                'unit' => $this->field('Satuan nilai', rules: ['required', 'string', 'max:20']),
                'sort_order' => $this->field('Urutan', 'number', ['required', 'integer', 'min:0'], default: 0),
                'is_active' => $this->toggle(),
            ]),
            'allergens' => $this->catalog('Jenis Alergen', 'Master alergen bahan dan penerima manfaat.', Allergen::class, 'allergens', false, ['code', 'name', 'sort_order'], [
                'code' => $this->field('Kode', rules: ['required', 'string', 'max:50'], unique: true, normalize: 'slug'),
                'name' => $this->field('Nama alergen', rules: ['required', 'string', 'max:255']),
                'sort_order' => $this->field('Urutan', 'number', ['required', 'integer', 'min:0'], default: 0),
                'description' => $this->field('Deskripsi', 'textarea', ['nullable', 'string', 'max:2000']),
                'is_active' => $this->toggle(),
            ]),
            'ingredients' => $this->catalog('Bahan Pangan', 'Bahan, konversi, nilai gizi, dan penanda alergen.', Ingredient::class, 'ingredients', true, ['code', 'name', 'category', 'measurement_unit_id'], [
                'code' => $this->field('Kode bahan', rules: ['required', 'string', 'max:50'], unique: true, normalize: 'upper'),
                'name' => $this->field('Nama bahan', rules: ['required', 'string', 'max:255']),
                'measurement_unit_id' => $this->field('Satuan utama', 'select', ['required', 'integer', 'exists:measurement_units,id'], source: 'measurement_units'),
                'category' => $this->field('Kategori', 'select', ['required', 'string'], options: ['staple' => 'Makanan Pokok', 'animal_protein' => 'Protein Hewani', 'plant_protein' => 'Protein Nabati', 'vegetable' => 'Sayuran', 'fruit' => 'Buah', 'seasoning' => 'Bumbu', 'oil' => 'Minyak dan Lemak', 'drink' => 'Minuman', 'dairy' => 'Susu dan Olahan', 'processed' => 'Bahan Olahan', 'other' => 'Lainnya']),
                'edible_portion_percent' => $this->field('Bagian dapat dimakan (%)', 'number', ['required', 'numeric', 'between:0,100'], default: 100),
                'grams_per_unit' => $this->field('Gram per satuan', 'number', ['nullable', 'numeric', 'gt:0']),
                'reference_price' => $this->field('Harga referensi per satuan beli', 'number', ['nullable', 'numeric', 'min:0']),
                'loss_factor' => $this->field('Faktor susut', 'number', ['required', 'numeric', 'gt:0'], default: 1),
                'rounding_increment' => $this->field('Kelipatan pembulatan', 'number', ['nullable', 'numeric', 'gt:0']),
                'rounding_mode' => $this->field('Cara pembulatan', 'select', ['required', 'string'], options: ['up' => 'Ke atas', 'nearest' => 'Terdekat', 'down' => 'Ke bawah'], default: 'up'),
                'nutrition_reference_grams' => $this->field('Basis nilai gizi (gram/satuan)', 'number', ['required', 'numeric', 'gt:0'], default: 100),
                'nutrition_source' => $this->field('Sumber nilai gizi'),
                'specification' => $this->field('Spesifikasi penerimaan', 'textarea', ['nullable', 'string', 'max:4000']),
                'description' => $this->field('Catatan tambahan', 'textarea', ['nullable', 'string', 'max:2000']),
                'is_active' => $this->toggle(),
            ], special: 'ingredient'),
            'nutrition-standards' => $this->catalog('Standar Gizi', 'Target komponen gizi per kategori dan periode.', NutritionStandard::class, 'nutrition', true, ['beneficiary_category_id', 'nutrition_component_id', 'target_value', 'effective_from'], [
                'beneficiary_category_id' => $this->field('Kategori penerima', 'select', ['required', 'integer', 'exists:beneficiary_categories,id'], source: 'beneficiary_categories'),
                'nutrition_component_id' => $this->field('Komponen gizi', 'select', ['required', 'integer', 'exists:nutrition_components,id'], source: 'nutrition_components'),
                'age_min_months' => $this->field('Usia minimum (bulan)', 'number', ['nullable', 'integer', 'between:0,1200']),
                'age_max_months' => $this->field('Usia maksimum (bulan)', 'number', ['nullable', 'integer', 'between:0,1200', 'gte:form.age_min_months']),
                'minimum_value' => $this->field('Nilai minimum', 'number', ['nullable', 'numeric', 'min:0']),
                'target_value' => $this->field('Nilai target', 'number', ['required', 'numeric', 'min:0']),
                'maximum_value' => $this->field('Nilai maksimum', 'number', ['nullable', 'numeric', 'min:0']),
                'effective_from' => $this->field('Berlaku mulai', 'date', ['required', 'date'], default: 'today'),
                'effective_until' => $this->field('Berlaku sampai', 'date', ['nullable', 'date', 'after_or_equal:form.effective_from']),
                'notes' => $this->field('Catatan', 'textarea', ['nullable', 'string', 'max:2000']),
                'is_active' => $this->toggle(),
            ]),
            'portion-standards' => $this->catalog(
                'Standar Gramasi Bahan',
                'Gramasi kecil dan besar per bahan berdasarkan sheet Standar Gizi siswa.',
                IngredientPortionStandard::class,
                'nutrition',
                true,
                [
                    'ingredient_id',
                    'component_type',
                    'measurement_unit_id',
                    'small_quantity',
                    'large_quantity',
                    'source',
                ],
                [
                    'ingredient_id' => $this->field(
                        'Bahan/makanan',
                        'select',
                        [
                            'required',
                            'integer',
                            'exists:ingredients,id',
                        ],
                        source: 'ingredients',
                    ),

                    'component_type' => $this->field(
                        'Komponen hidangan',
                        'select',
                        [
                            'nullable',
                            'string',
                            'max:40',
                        ],
                        options: [
                            'staple' => 'Karbohidrat/Makanan Pokok',
                            'animal_protein' => 'Protein Hewani',
                            'plant_protein' => 'Protein Nabati',
                            'vegetable' => 'Sayur',
                            'fruit' => 'Buah',
                            'milk' => 'Susu',
                            'seasoning' => 'Bumbu',
                            'other' => 'Lainnya',
                        ],
                    ),

                    'measurement_unit_id' => $this->field(
                        'Satuan resep',
                        'select',
                        [
                            'required',
                            'integer',
                            'exists:measurement_units,id',
                        ],
                        source: 'measurement_units',
                    ),

                    'small_quantity' => $this->field(
                        'Porsi kecil',
                        'number',
                        [
                            'required',
                            'numeric',
                            'gt:0',
                        ],
                    ),

                    'large_quantity' => $this->field(
                        'Porsi besar',
                        'number',
                        [
                            'required',
                            'numeric',
                            'gt:0',
                        ],
                    ),

                    'toddler_quantity' => $this->field(
                        'Porsi khusus Balita',
                        'number',
                        [
                            'nullable',
                            'numeric',
                            'gt:0',
                        ],
                    ),

                    'maternal_quantity' => $this->field(
                        'Porsi khusus Bumil/Busui',
                        'number',
                        [
                            'nullable',
                            'numeric',
                            'gt:0',
                        ],
                    ),

                    'grams_per_unit' => $this->field(
                        'Gram per satuan',
                        'number',
                        [
                            'required',
                            'numeric',
                            'gt:0',
                        ],
                        default: 1,
                    ),

                    'source' => $this->field(
                        'Sumber standar',
                        'text',
                        [
                            'required',
                            'string',
                            'max:160',
                        ],
                        default: 'Standar Gizi siswa',
                    ),

                    'source_row' => $this->field(
                        'Baris sumber spreadsheet',
                        'number',
                        [
                            'nullable',
                            'integer',
                            'min:1',
                        ],
                    ),

                    'is_active' => $this->toggle(),
                ],
            ),
            'suppliers' => $this->catalog('Supplier', 'Pemasok yang dapat dipilih pada proses pengadaan.', Supplier::class, 'suppliers', true, ['code', 'name', 'contact_person', 'phone'], [
                'code' => $this->field('Kode', rules: ['required', 'string', 'max:50'], unique: true, normalize: 'upper'),
                'name' => $this->field('Nama supplier', rules: ['required', 'string', 'max:255']),
                'contact_person' => $this->field('Kontak person'),
                'phone' => $this->field('Nomor telepon', 'tel'),
                'address' => $this->field('Alamat', 'textarea', ['nullable', 'string', 'max:2000']),
                'notes' => $this->field('Catatan', 'textarea', ['nullable', 'string', 'max:2000']),
                'is_active' => $this->toggle(),
            ]),
            'service-holidays' => $this->catalog('Hari Libur Pelayanan', 'Tanggal layanan yang dikecualikan dari perencanaan.', ServiceHoliday::class, 'menus', true, ['holiday_date', 'name', 'holiday_type'], [
                'holiday_date' => $this->field('Tanggal', 'date', ['required', 'date']),
                'name' => $this->field('Nama hari libur', rules: ['required', 'string', 'max:255']),
                'holiday_type' => $this->field('Jenis', 'select', ['required', 'string'], options: ['national' => 'Libur Nasional', 'local' => 'Libur Daerah', 'operational' => 'Libur Operasional SPPG']),
                'notes' => $this->field('Catatan', 'textarea', ['nullable', 'string', 'max:2000']),
                'is_active' => $this->toggle(),
            ]),
            'cleaning-areas' => $this->catalog('Area Kebersihan', 'Area, template resmi, jadwal, dan SOP Kebersihan.', CleaningArea::class, 'cleaning', true, ['code', 'name', 'template_type', 'frequency'], [
                'code' => $this->field('Kode area', rules: ['required', 'string', 'max:50'], unique: true, normalize: 'upper'),
                'name' => $this->field('Nama area', rules: ['required', 'string', 'max:255']),
                'category' => $this->field('Kategori', 'select', ['required', 'string'], options: [
                    'toilet' => 'Toilet', 'production' => 'Produksi', 'portioning' => 'Pemorsian',
                    'warehouse' => 'Gudang', 'washing' => 'Pencucian', 'other' => 'Lainnya',
                ]),
                'template_type' => $this->field('Template checklist', 'select', ['required', 'string'], options: [
                    'toilet' => 'Toilet', 'production' => 'Area Produksi', 'portioning' => 'Area Pemorsian',
                    'warehouse' => 'Gudang', 'custom' => 'Checklist khusus',
                ]),
                'location' => $this->field('Lokasi/detail posisi'),
                'frequency' => $this->field('Frekuensi standar', 'select', ['required', 'string'], options: ['daily' => 'Harian', 'weekly' => 'Mingguan', 'as_needed' => 'Sesuai Kebutuhan']),
                'auto_schedule' => $this->field('Buat jadwal otomatis', 'toggle', ['boolean'], default: true),
                'scheduled_time' => $this->field('Jam jadwal', 'time', ['nullable']),
                'standard_duration_minutes' => $this->field('Durasi standar (menit)', 'number', ['nullable', 'integer', 'min:1']),
                'instructions' => $this->field('Instruksi/SOP ringkas', 'textarea', ['nullable', 'string', 'max:4000']),
                'is_active' => $this->toggle(),
            ], special: 'cleaning'),
            'divisions' => $this->catalog('Divisi', 'Struktur divisi operasional dan urutannya.', Division::class, 'divisions', false, ['code', 'name', 'sort_order'], [
                'code' => $this->field('Kode divisi', rules: ['required', 'string', 'max:50'], unique: true, normalize: 'slug'),
                'name' => $this->field('Nama divisi', rules: ['required', 'string', 'max:255']),
                'sort_order' => $this->field('Urutan menu', 'number', ['required', 'integer', 'min:0'], default: 0),
                'description' => $this->field('Deskripsi', 'textarea', ['nullable', 'string', 'max:2000']),
                'is_active' => $this->toggle(),
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public function get(string $slug): array
    {
        abort_unless(array_key_exists($slug, $this->all()), 404);

        return $this->all()[$slug];
    }

    /** @return array<int, string> */
    public function slugs(): array
    {
        return array_keys($this->all());
    }

    /** @return array<int|string, string> */
    public function options(string $source, int $unitId): array
    {
        return match ($source) {
            'measurement_units' => MeasurementUnit::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->mapWithKeys(
                    fn($item) => [
                        $item->id => "{$item->name} ({$item->symbol})",
                    ]
                )
                ->all(),

            'beneficiary_categories' => BeneficiaryCategory::query()
                ->where('sppg_unit_id', $unitId)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->pluck('name', 'id')
                ->all(),

            'nutrition_components' => NutritionComponent::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->mapWithKeys(
                    fn($item) => [
                        $item->id => "{$item->name} ({$item->unit})",
                    ]
                )
                ->all(),

            'ingredients' => Ingredient::query()
                ->where('sppg_unit_id', $unitId)
                ->where('is_active', true)
                ->orderBy('name')
                ->get()
                ->mapWithKeys(
                    fn(Ingredient $ingredient): array => [
                        $ingredient->id => "{$ingredient->name} ({$ingredient->code})",
                    ]
                )
                ->all(),

            default => [],
        };
    }

    public function normalize(string $mode, mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match ($mode) {
            'upper' => Str::upper(trim($value)),
            'lower' => Str::lower(trim($value)),
            'slug' => Str::slug(Str::lower($value), '_'),
            default => trim($value),
        };
    }

    /** @param array<string, mixed> $fields @return array<string, mixed> */
    private function catalog(string $label, string $description, string $model, string $permission, bool $unitScoped, array $listFields, array $fields, ?string $special = null): array
    {
        return compact('label', 'description', 'model', 'permission', 'unitScoped', 'listFields', 'fields', 'special');
    }

    /** @return array<string, mixed> */
    private function field(string $label, string $type = 'text', array $rules = ['nullable', 'string', 'max:255'], bool $unique = false, mixed $default = '', array $options = [], ?string $source = null, ?string $normalize = null): array
    {
        return compact('label', 'type', 'rules', 'unique', 'default', 'options', 'source', 'normalize');
    }

    /** @return array<string, mixed> */
    private function toggle(): array
    {
        return $this->field('Aktif', 'toggle', ['boolean'], default: true);
    }
}
