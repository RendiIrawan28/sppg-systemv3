<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MbgV14NutritionSeeder extends Seeder
{
    private const SOURCE_MARKER = '[MBG_V14_SEED]';

    /** @var array<int, string> */
    private const REQUIRED_TABLES = [
        'sppg_units',
        'measurement_units',
        'nutrition_components',
        'beneficiary_categories',
        'ingredients',
        'ingredient_nutritions',
        'nutrition_standards',
    ];

    /** @var array<int, string> */
    private const VALIDATED_COMPONENTS = [
        'energy',
        'protein',
        'fat',
        'carbohydrate',
        'fiber',
    ];

    /** @var array<string, array<int, string>> */
    private array $columnCache = [];

    public function run(): void
    {
        $this->assertRequiredTables();

        $data = $this->loadData();
        $now = now();

        DB::transaction(function () use ($data, $now): void {
            $this->seedMeasurementUnits($now);
            $this->seedNutritionComponents($data['components'], $now);
            $this->seedAllergens($now);

            $unitIds = DB::table('sppg_units')
                ->where('is_active', true)
                ->orderBy('id')
                ->pluck('id');

            if ($unitIds->isEmpty()) {
                throw new RuntimeException('Tidak ada Unit SPPG aktif. Jalankan seeder unit terlebih dahulu.');
            }

            foreach ($unitIds as $unitId) {
                $this->seedBeneficiaryCategories((int) $unitId, $now);
                $this->seedIngredients(
                    unitId: (int) $unitId,
                    ingredients: $data['ingredients'],
                    now: $now,
                );
                $this->seedNutritionStandards(
                    unitId: (int) $unitId,
                    standards: $data['standards'],
                    now: $now,
                );
                (new MbgV14PortionStandardSeeder())->runForUnit((int) $unitId);
            }
        }, 3);

        $this->command?->info(sprintf(
            'Seeder referensi gizi MBG selesai: %d bahan, %d komponen, satuan, alergen, kategori penerima, dan standar gizi diseragamkan.',
            count($data['ingredients']),
            count($this->canonicalNutritionComponents($data['components'])),
        ));
    }

    private function assertRequiredTables(): void
    {
        $missing = array_values(array_filter(
            self::REQUIRED_TABLES,
            fn (string $table): bool => ! Schema::hasTable($table),
        ));

        if ($missing !== []) {
            throw new RuntimeException(
                'Tabel belum tersedia: '.implode(', ', $missing).'. Jalankan php artisan migrate terlebih dahulu.'
            );
        }
    }

    /**
     * @return array{
     *     components: array<int, array<string, mixed>>,
     *     standards: array<string, array<string, float|int|null>>,
     *     ingredients: array<int, array<string, mixed>>
     * }
     */
    private function loadData(): array
    {
        $path = database_path('seeders/data/mbg_v14_nutrition.json');

        if (! is_file($path)) {
            throw new RuntimeException("File data seeder tidak ditemukan: {$path}");
        }

        $decoded = json_decode(file_get_contents($path) ?: '', true, 512, JSON_THROW_ON_ERROR);

        if (
            ! isset($decoded['components'], $decoded['standards'], $decoded['ingredients']) ||
            ! is_array($decoded['components']) ||
            ! is_array($decoded['standards']) ||
            ! is_array($decoded['ingredients'])
        ) {
            throw new RuntimeException('Struktur data mbg_v14_nutrition.json tidak valid.');
        }

        return $decoded;
    }

    /** @return array<int, string> */
    private function columns(string $table): array
    {
        return $this->columnCache[$table] ??= Schema::getColumnListing($table);
    }

    /** @return array<string, mixed> */
    private function filterPayload(string $table, array $payload): array
    {
        return array_intersect_key($payload, array_flip($this->columns($table)));
    }

    private function updateOrInsert(string $table, array $keys, array $values): void
    {
        DB::table($table)->updateOrInsert(
            $this->filterPayload($table, $keys),
            $this->filterPayload($table, $values),
        );
    }

    private function seedMeasurementUnits(mixed $now): void
    {
        $rows = [
            ['code' => 'g', 'name' => 'Gram', 'symbol' => 'g', 'unit_type' => 'weight', 'to_base_factor' => 1, 'sort_order' => 1],
            ['code' => 'kg', 'name' => 'Kilogram', 'symbol' => 'kg', 'unit_type' => 'weight', 'to_base_factor' => 1000, 'sort_order' => 2],
            ['code' => 'mg', 'name' => 'Miligram', 'symbol' => 'mg', 'unit_type' => 'weight', 'to_base_factor' => 0.001, 'sort_order' => 3],
            ['code' => 'ml', 'name' => 'Mililiter', 'symbol' => 'ml', 'unit_type' => 'volume', 'to_base_factor' => 1, 'sort_order' => 4],
            ['code' => 'l', 'name' => 'Liter', 'symbol' => 'l', 'unit_type' => 'volume', 'to_base_factor' => 1000, 'sort_order' => 5],
            ['code' => 'pcs', 'name' => 'Buah/Potong/Satuan', 'symbol' => 'pcs', 'unit_type' => 'count', 'to_base_factor' => 1, 'sort_order' => 6],
            ['code' => 'pack', 'name' => 'Kemasan', 'symbol' => 'pack', 'unit_type' => 'count', 'to_base_factor' => 1, 'sort_order' => 7],
        ];

        foreach ($rows as $row) {
            $this->updateOrInsert(
                'measurement_units',
                ['code' => $row['code']],
                [
                    ...$row,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $sourceComponents
     * @return array<int, array{code:string,name:string,unit:string,sort_order:int}>
     */
    private function canonicalNutritionComponents(array $sourceComponents): array
    {
        $rows = [];

        foreach ($sourceComponents as $component) {
            $code = strtolower((string) $component['code']);
            $rows[$code] = [
                'code' => $code,
                'name' => (string) $component['name'],
                'unit' => $this->normalizeComponentUnit((string) $component['unit']),
                'sort_order' => (int) $component['sort_order'],
            ];
        }

        $additional = [
            ['code' => 'water', 'name' => 'Air', 'unit' => 'g', 'sort_order' => 101],
            ['code' => 'ash', 'name' => 'Abu', 'unit' => 'g', 'sort_order' => 102],
            ['code' => 'calcium', 'name' => 'Kalsium', 'unit' => 'mg', 'sort_order' => 103],
            ['code' => 'phosphorus', 'name' => 'Fosfor', 'unit' => 'mg', 'sort_order' => 104],
            ['code' => 'potassium', 'name' => 'Kalium', 'unit' => 'mg', 'sort_order' => 105],
            ['code' => 'copper', 'name' => 'Tembaga', 'unit' => 'mg', 'sort_order' => 106],
            ['code' => 'zinc', 'name' => 'Seng', 'unit' => 'mg', 'sort_order' => 107],
            ['code' => 'retinol', 'name' => 'Retinol', 'unit' => 'mcg', 'sort_order' => 108],
            ['code' => 'beta_carotene', 'name' => 'Beta-karoten', 'unit' => 'mcg', 'sort_order' => 109],
            ['code' => 'total_carotene', 'name' => 'Karoten Total', 'unit' => 'mcg', 'sort_order' => 110],
            ['code' => 'thiamin', 'name' => 'Thiamin (Vitamin B1)', 'unit' => 'mg', 'sort_order' => 111],
            ['code' => 'riboflavin', 'name' => 'Riboflavin (Vitamin B2)', 'unit' => 'mg', 'sort_order' => 112],
            ['code' => 'niacin', 'name' => 'Niasin (Vitamin B3)', 'unit' => 'mg', 'sort_order' => 113],
        ];

        foreach ($additional as $component) {
            $rows[$component['code']] ??= $component;
        }

        uasort($rows, fn (array $a, array $b): int => $a['sort_order'] <=> $b['sort_order']);

        return array_values($rows);
    }

    private function normalizeComponentUnit(string $unit): string
    {
        return match (strtolower($unit)) {
            'kkal' => 'kcal',
            'µg' => 'mcg',
            default => $unit,
        };
    }

    /** @param array<int, array<string, mixed>> $components */
    private function seedNutritionComponents(array $components, mixed $now): void
    {
        foreach ($this->canonicalNutritionComponents($components) as $component) {
            $this->updateOrInsert(
                'nutrition_components',
                ['code' => $component['code']],
                [
                    ...$component,
                    'description' => self::SOURCE_MARKER.' Komponen gizi referensi. Kode diseragamkan untuk MBG dan TKPI.',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    private function seedAllergens(mixed $now): void
    {
        if (! Schema::hasTable('allergens')) {
            return;
        }

        $items = self::allergenRows();

        foreach ($items as $item) {
            $this->updateOrInsert(
                'allergens',
                ['code' => $item['code']],
                [
                    ...$item,
                    'description' => self::SOURCE_MARKER.' Master jenis alergen bahan/menu.',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    /** @return array<int, array{code:string,name:string,sort_order:int}> */
    public static function allergenRows(): array
    {
        return [
            ['code' => 'gluten', 'name' => 'Gluten', 'sort_order' => 1],
            ['code' => 'crustacean', 'name' => 'Krustasea/Udang/Kepiting', 'sort_order' => 2],
            ['code' => 'egg', 'name' => 'Telur', 'sort_order' => 3],
            ['code' => 'fish', 'name' => 'Ikan', 'sort_order' => 4],
            ['code' => 'peanut', 'name' => 'Kacang Tanah', 'sort_order' => 5],
            ['code' => 'soy', 'name' => 'Kedelai', 'sort_order' => 6],
            ['code' => 'milk', 'name' => 'Susu dan Produk Susu', 'sort_order' => 7],
            ['code' => 'tree_nut', 'name' => 'Kacang Pohon', 'sort_order' => 8],
            ['code' => 'sesame', 'name' => 'Wijen', 'sort_order' => 9],
            ['code' => 'mollusc', 'name' => 'Moluska/Kerang/Cumi', 'sort_order' => 10],
            ['code' => 'sulfite', 'name' => 'Sulfit', 'sort_order' => 11],
            ['code' => 'lupin', 'name' => 'Lupin', 'sort_order' => 12],
            ['code' => 'celery', 'name' => 'Seledri', 'sort_order' => 13],
            ['code' => 'mustard', 'name' => 'Mustard', 'sort_order' => 14],
            ['code' => 'other', 'name' => 'Alergen Lainnya', 'sort_order' => 99],
        ];
    }

    private function seedBeneficiaryCategories(int $unitId, mixed $now): void
    {
        $categories = [
            ['code' => 'paud', 'name' => 'PAUD', 'group_type' => 'education', 'education_level' => 'paud', 'grade_start' => null, 'grade_end' => null, 'portion_size' => 'small', 'menu_audience' => 'student', 'sort_order' => 1],
            ['code' => 'tk', 'name' => 'TK', 'group_type' => 'education', 'education_level' => 'tk', 'grade_start' => null, 'grade_end' => null, 'portion_size' => 'small', 'menu_audience' => 'student', 'sort_order' => 2],
            ['code' => 'sd_1_3', 'name' => 'SD Kelas 1–3', 'group_type' => 'education', 'education_level' => 'sd', 'grade_start' => 1, 'grade_end' => 3, 'portion_size' => 'small', 'menu_audience' => 'student', 'sort_order' => 3],
            ['code' => 'sd_4_6', 'name' => 'SD Kelas 4–6', 'group_type' => 'education', 'education_level' => 'sd', 'grade_start' => 4, 'grade_end' => 6, 'portion_size' => 'large', 'menu_audience' => 'student', 'sort_order' => 4],
            ['code' => 'smp', 'name' => 'SMP', 'group_type' => 'education', 'education_level' => 'smp', 'grade_start' => null, 'grade_end' => null, 'portion_size' => 'large', 'menu_audience' => 'student', 'sort_order' => 5],
            ['code' => 'sma', 'name' => 'SMA', 'group_type' => 'education', 'education_level' => 'sma', 'grade_start' => null, 'grade_end' => null, 'portion_size' => 'large', 'menu_audience' => 'student', 'sort_order' => 6],
            ['code' => 'balita', 'name' => 'Balita', 'group_type' => 'b3', 'education_level' => null, 'grade_start' => null, 'grade_end' => null, 'portion_size' => 'small', 'menu_audience' => 'toddler', 'sort_order' => 7],
            ['code' => 'ibu_hamil', 'name' => 'Ibu Hamil', 'group_type' => 'b3', 'education_level' => null, 'grade_start' => null, 'grade_end' => null, 'portion_size' => 'large', 'menu_audience' => 'maternal', 'sort_order' => 8],
            ['code' => 'ibu_menyusui', 'name' => 'Ibu Menyusui', 'group_type' => 'b3', 'education_level' => null, 'grade_start' => null, 'grade_end' => null, 'portion_size' => 'large', 'menu_audience' => 'maternal', 'sort_order' => 9],
            ['code' => 'guru', 'name' => 'Guru', 'group_type' => 'staff', 'education_level' => null, 'grade_start' => null, 'grade_end' => null, 'portion_size' => 'large', 'menu_audience' => 'student', 'sort_order' => 10],
            ['code' => 'tendik', 'name' => 'Tenaga Kependidikan', 'group_type' => 'staff', 'education_level' => null, 'grade_start' => null, 'grade_end' => null, 'portion_size' => 'large', 'menu_audience' => 'student', 'sort_order' => 11],
        ];

        foreach ($categories as $category) {
            $this->updateOrInsert(
                'beneficiary_categories',
                ['sppg_unit_id' => $unitId, 'code' => $category['code']],
                [
                    ...$category,
                    'sppg_unit_id' => $unitId,
                    'description' => self::SOURCE_MARKER.' Kategori penerima untuk validasi gizi MBG.',
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    /** @param array<int, array<string, mixed>> $ingredients */
    private function seedIngredients(int $unitId, array $ingredients, mixed $now): void
    {
        $unitMap = DB::table('measurement_units')
            ->whereIn('code', ['g', 'kg', 'pcs'])
            ->pluck('id', 'code');

        $componentMap = DB::table('nutrition_components')
            ->pluck('id', 'code');

        if (! isset($unitMap['kg'], $unitMap['pcs'])) {
            throw new RuntimeException('Satuan kg dan pcs belum tersedia.');
        }

        foreach ($ingredients as $ingredient) {
            $unitCode = (string) ($ingredient['measurement_unit_code'] ?? 'kg');
            $code = (string) $ingredient['code'];

            $this->updateOrInsert(
                'ingredients',
                ['sppg_unit_id' => $unitId, 'code' => $code],
                [
                    'sppg_unit_id' => $unitId,
                    'measurement_unit_id' => (int) ($unitMap[$unitCode] ?? $unitMap['kg']),
                    'code' => $code,
                    'name' => (string) $ingredient['name'],
                    'category' => (string) ($ingredient['category'] ?? 'other'),
                    'edible_portion_percent' => (float) ($ingredient['edible_portion_percent'] ?? 100),
                    'reference_price' => $ingredient['reference_price'] ?? null,
                    'loss_factor' => (float) ($ingredient['reference_loss_factor'] ?? 1),
                    'rounding_increment' => $this->roundingIncrement($ingredient['reference_rounding'] ?? null),
                    'rounding_mode' => 'up',
                    // Workbook memakai basis 1 untuk bahan bersatuan SATUAN/pcs
                    // dan basis 100 untuk bahan berbobot gram/kilogram.
                    'nutrition_reference_grams' => $unitCode === 'pcs' ? 1 : 100,
                    'nutrition_source' => $ingredient['source'] ?? null,
                    'specification' => $this->extractSpecification($ingredient['description'] ?? null),
                    'source_row' => $ingredient['source_row'] ?? null,
                    'description' => (string) ($ingredient['description'] ?? self::SOURCE_MARKER.' Bahan referensi MBG.'),
                    'photo_path' => null,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        $ingredientIds = DB::table('ingredients')
            ->where('sppg_unit_id', $unitId)
            ->whereIn('code', array_column($ingredients, 'code'))
            ->pluck('id', 'code');

        $nutritionCount = 0;

        foreach ($ingredients as $ingredient) {
            $ingredientId = $ingredientIds[$ingredient['code']] ?? null;

            if (! $ingredientId) {
                continue;
            }

            foreach ((array) ($ingredient['nutritions'] ?? []) as $code => $value) {
                $componentCode = strtolower((string) $code);
                $componentId = $componentMap[$componentCode] ?? null;

                if (! $componentId) {
                    continue;
                }

                $this->updateOrInsert(
                    'ingredient_nutritions',
                    [
                        'ingredient_id' => (int) $ingredientId,
                        'nutrition_component_id' => (int) $componentId,
                    ],
                    [
                        'ingredient_id' => (int) $ingredientId,
                        'nutrition_component_id' => (int) $componentId,
                        'value_per_100g' => max(0, (float) $value),
                        'source' => mb_substr((string) ($ingredient['source'] ?? 'MBG PUNYA CERITA V14'), 0, 255),
                        'notes' => self::SOURCE_MARKER.' DAFTAR BAHAN baris '.(int) ($ingredient['source_row'] ?? 0).'.',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );

                $nutritionCount++;
            }
        }

        $this->seedIngredientAllergens($ingredientIds->all(), $ingredients, $now);

        $this->command?->line(sprintf(
            'Unit %d: %d bahan MBG dan %d nilai gizi MBG diproses.',
            $unitId,
            count($ingredients),
            $nutritionCount,
        ));
    }

    private function roundingIncrement(mixed $rule): ?float
    {
        $rule = strtoupper(trim((string) $rule));

        if ($rule === '' || str_contains($rule, 'TIDAK DIBULATKAN')) {
            return null;
        }

        if (preg_match('/([0-9]+(?:[,.][0-9]+)?)/', $rule, $matches) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $matches[1]);
    }

    private function extractSpecification(mixed $description): ?string
    {
        $description = trim((string) $description);

        if (preg_match('/(?:^|\n)Spesifikasi:\s*(.+)$/isu', $description, $matches) !== 1) {
            return null;
        }

        return trim($matches[1]);
    }

    /**
     * @param array<string, int|string> $ingredientIds
     * @param array<int, array<string, mixed>> $ingredients
     */
    private function seedIngredientAllergens(array $ingredientIds, array $ingredients, mixed $now): void
    {
        if (! Schema::hasTable('allergens') || ! Schema::hasTable('ingredient_allergen')) {
            return;
        }

        $allergenIds = DB::table('allergens')->pluck('id', 'code');

        foreach ($ingredients as $ingredient) {
            $ingredientId = $ingredientIds[$ingredient['code']] ?? null;

            if (! $ingredientId) {
                continue;
            }

            foreach ($this->detectAllergenCodes((string) $ingredient['name']) as $allergenCode) {
                $allergenId = $allergenIds[$allergenCode] ?? null;

                if (! $allergenId) {
                    continue;
                }

                $this->updateOrInsert(
                    'ingredient_allergen',
                    [
                        'ingredient_id' => (int) $ingredientId,
                        'allergen_id' => (int) $allergenId,
                    ],
                    [
                        'ingredient_id' => (int) $ingredientId,
                        'allergen_id' => (int) $allergenId,
                        'contamination_risk' => false,
                        'notes' => self::SOURCE_MARKER.' Deteksi otomatis dari nama bahan; verifikasi kembali oleh Ahli Gizi.',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }

    /** @return array<int, string> */
    private function detectAllergenCodes(string $name): array
    {
        $value = mb_strtoupper($name);
        $codes = [];

        $rules = [
            'egg' => ['TELUR', 'MAYONAISE', 'MAYONES'],
            'fish' => ['IKAN', 'TUNA', 'KAKAP', 'LELE', 'DORI', 'BANDENG', 'KEMBUNG', 'TONGKOL', 'NILA', 'PATIN', 'GURAME', 'SALMON', 'SARDEN', 'TERI'],
            'crustacean' => ['UDANG', 'EBI', 'KEPITING', 'RAJUNGAN'],
            'mollusc' => ['CUMI', 'KERANG', 'GURITA'],
            'soy' => ['KEDELAI', 'TEMPE', 'TAHU', 'EDAMAME', 'TAUCO', 'KECAP', 'SUSU KEDELAI'],
            'peanut' => ['KACANG TANAH', 'SELAI KACANG'],
            'tree_nut' => ['ALMOND', 'CASHEW', 'METE', 'HAZELNUT'],
            'sesame' => ['WIJEN', 'SESAME'],
            'milk' => ['SUSU', 'MILK', 'KEJU', 'CHEESE', 'YOGURT', 'YOGHURT', 'DANCOW', 'PRENAGEN', 'CREAMER'],
            'gluten' => ['TERIGU', 'ROTI', 'MIE', 'SPAGHETTI', 'MAKARONI', 'MACARONI', 'BISKUIT', 'BISCUIT', 'MALKIST', 'CRACKER', 'PANGSIT', 'TORTILA', 'WAFER'],
            'sulfite' => ['SULFIT', 'SULPHITE', 'SULFITE'],
            'celery' => ['SELEDRI', 'CELERY'],
            'mustard' => ['MUSTARD'],
        ];

        foreach ($rules as $code => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($value, $keyword)) {
                    $codes[] = $code;
                    break;
                }
            }
        }

        return array_values(array_unique($codes));
    }

    /** @param array<string, array<string, float|int|null>> $standards */
    private function seedNutritionStandards(int $unitId, array $standards, mixed $now): void
    {
        $categoryIds = DB::table('beneficiary_categories')
            ->where('sppg_unit_id', $unitId)
            ->pluck('id', 'code');

        $componentIds = DB::table('nutrition_components')
            ->pluck('id', 'code');

        foreach ($standards as $categoryCode => $componentValues) {
            $categoryId = $categoryIds[$categoryCode] ?? null;

            if (! $categoryId) {
                $this->command?->warn("Kategori {$categoryCode} tidak ditemukan pada Unit {$unitId}; standar dilewati.");
                continue;
            }

            foreach ($componentValues as $componentCode => $targetValue) {
                if ($targetValue === null || $targetValue === '') {
                    continue;
                }

                $componentCode = strtolower((string) $componentCode);
                $componentId = $componentIds[$componentCode] ?? null;

                if (! $componentId) {
                    continue;
                }

                $target = round((float) $targetValue, 4);
                $validated = in_array($componentCode, self::VALIDATED_COMPONENTS, true);

                $notes = self::SOURCE_MARKER.' Target makan siang dari sheet Standar Gizi siswa.';

                if (
                    in_array($categoryCode, ['ibu_hamil', 'ibu_menyusui', 'guru', 'tendik'], true) &&
                    $componentCode === 'fiber'
                ) {
                    $notes .= ' Target serat 9,6 g diturunkan dari 30% AKG harian 32 g karena sel serat pada workbook bernilai 0.';
                }

                if (in_array($categoryCode, ['guru', 'tendik'], true)) {
                    $notes .= ' Guru/Tendik menggunakan profil menu siswa porsi besar.';
                }

                $this->updateOrInsert(
                    'nutrition_standards',
                    [
                        'sppg_unit_id' => $unitId,
                        'beneficiary_category_id' => (int) $categoryId,
                        'nutrition_component_id' => (int) $componentId,
                        'effective_from' => '2026-01-01',
                    ],
                    [
                        'sppg_unit_id' => $unitId,
                        'beneficiary_category_id' => (int) $categoryId,
                        'nutrition_component_id' => (int) $componentId,
                        'age_min_months' => null,
                        'age_max_months' => null,
                        'minimum_value' => $validated ? round($target * 0.90, 4) : null,
                        'target_value' => $target,
                        'maximum_value' => $validated
                            ? round($target * 1.10, 4)
                            : ($componentCode === 'sodium' ? $target : null),
                        'effective_from' => '2026-01-01',
                        'effective_until' => null,
                        'notes' => $notes,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }
}
