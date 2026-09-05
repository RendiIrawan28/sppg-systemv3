<?php

namespace Database\Seeders;

use App\Models\Ingredient;
use App\Models\MeasurementUnit;
use App\Models\OpeningStock;
use App\Models\SppgUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\OpeningStockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class OpeningWarehouseStockSeeder extends Seeder
{
    private const UNIT_CODE = 'SPPG-001';

    private const OPENING_DATE = '2026-09-04';

    private const SOURCE_MARKER = 'SEED-EXCEL-STOK-2026-09-04';

    public function run(): void
    {
        $unit = SppgUnit::query()->where('code', self::UNIT_CODE)->first();

        if (! $unit) {
            throw new RuntimeException(
                'Unit '.self::UNIT_CODE.' belum tersedia. Jalankan AccessControlSeeder terlebih dahulu.'
            );
        }

        $warehouse = Warehouse::forUnit((int) $unit->getKey(), Warehouse::TYPE_FOOD);

        // Idempotent: bila seeder ini pernah sukses, jangan menambah saldo dua kali.
        $alreadySeeded = OpeningStock::query()
            ->where('sppg_unit_id', $unit->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->whereDate('opening_date', self::OPENING_DATE)
            ->where('notes', 'like', self::SOURCE_MARKER.'%')
            ->exists();

        if ($alreadySeeded) {
            $this->command?->warn('Stok awal Excel 04-09-2026 sudah pernah dimasukkan. Seeder dilewati.');

            return;
        }

        // Pengaman produksi: jangan menambah stok awal di atas transaksi stok yang sudah berjalan.
        $existingMovements = DB::table('stock_movements')
            ->where('sppg_unit_id', $unit->getKey())
            ->where('warehouse_id', $warehouse->getKey())
            ->count();

        if ($existingMovements > 0) {
            throw new RuntimeException(
                'Gudang pangan sudah memiliki '.$existingMovements.' mutasi stok. '.
                'Seeder stok awal dibatalkan agar saldo tidak terhitung ganda. '.
                'Bersihkan data uji stok terlebih dahulu atau gunakan penyesuaian stok untuk data yang sudah operasional.'
            );
        }

        $this->seedMeasurementUnits();
        $ingredientIds = $this->syncIngredientMaster((int) $unit->getKey());

        $actor = User::query()
            ->where('employee_number', '91D5286F')
            ->where('is_active', true)
            ->first()
            ?? User::query()->where('email', 'admin@sppg.test')->first()
            ?? User::query()->where('is_active', true)->first();

        if (! $actor) {
            throw new RuntimeException(
                'Belum ada user aktif untuk menjadi pencatat stok awal. Jalankan EmployeeUserSeeder terlebih dahulu.'
            );
        }

        $rows = collect($this->items())
            ->filter(fn (array $item): bool => (float) $item['ending'] > 0)
            ->map(function (array $item) use ($ingredientIds): array {
                return [
                    'ingredient_id' => $ingredientIds[$item['code']],
                    'quantity' => (float) $item['ending'],
                    'lot_number' => null,
                    'expired_date' => null,
                    'storage_type' => $item['storage_type'],
                    'location_name' => $item['location_name'],
                    'condition_notes' => 'Saldo akhir Excel per 04-09-2026. Kode sumber: '.$item['code'],
                ];
            })
            ->values()
            ->all();

        $photoPath = $this->ensureSeedPhoto();

        app(OpeningStockService::class)->createForWarehouse(
            (int) $unit->getKey(),
            self::OPENING_DATE,
            $photoPath,
            self::SOURCE_MARKER.' | Impor saldo akhir Laporan Stok Barang SPPG Nogotirto.',
            $rows,
            $actor,
            Warehouse::TYPE_FOOD,
        );

        $this->command?->info(
            count($this->items()).' master barang disinkronkan; '.
            count($rows).' barang dengan saldo > 0 dimasukkan sebagai stok awal per 04-09-2026.'
        );
    }

    private function seedMeasurementUnits(): void
    {
        $units = [
            ['code' => 'kg', 'name' => 'Kilogram', 'symbol' => 'kg', 'unit_type' => 'weight', 'to_base_factor' => 1000],
            ['code' => 'pcs', 'name' => 'Buah/Potong/Satuan', 'symbol' => 'pcs', 'unit_type' => 'count', 'to_base_factor' => 1],
            ['code' => 'pack', 'name' => 'Kemasan', 'symbol' => 'pack', 'unit_type' => 'count', 'to_base_factor' => 1],
            ['code' => 'botol', 'name' => 'Botol', 'symbol' => 'botol', 'unit_type' => 'count', 'to_base_factor' => 1],
            ['code' => 'box', 'name' => 'Box', 'symbol' => 'box', 'unit_type' => 'count', 'to_base_factor' => 1],
            ['code' => 'ikat', 'name' => 'Ikat', 'symbol' => 'ikat', 'unit_type' => 'count', 'to_base_factor' => 1],
            ['code' => 'jirigen', 'name' => 'Jirigen', 'symbol' => 'jirigen', 'unit_type' => 'count', 'to_base_factor' => 1],
            ['code' => 'bag', 'name' => 'Bag', 'symbol' => 'bag', 'unit_type' => 'count', 'to_base_factor' => 1],
            ['code' => 'slice', 'name' => 'Slice', 'symbol' => 'slice', 'unit_type' => 'count', 'to_base_factor' => 1],
            ['code' => 'papan', 'name' => 'Papan', 'symbol' => 'papan', 'unit_type' => 'count', 'to_base_factor' => 1],
            ['code' => 'biji', 'name' => 'Biji', 'symbol' => 'biji', 'unit_type' => 'count', 'to_base_factor' => 1],
        ];

        foreach ($units as $unit) {
            MeasurementUnit::query()->updateOrCreate(
                ['code' => $unit['code']],
                [
                    'name' => $unit['name'],
                    'symbol' => $unit['symbol'],
                    'unit_type' => $unit['unit_type'],
                    'to_base_factor' => $unit['to_base_factor'],
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Membuat/menyelaraskan master bahan dari daftar Excel.
     * Barang yang sudah ada dicocokkan dengan kode terlebih dahulu, lalu nama.
     * Satuan mengikuti satuan stok Excel agar saldo awal tidak berubah makna.
     *
     * @return array<string, int> [kode_excel => ingredient_id]
     */
    private function syncIngredientMaster(int $unitId): array
    {
        $unitIds = MeasurementUnit::query()
            ->whereIn('code', collect($this->items())->pluck('unit')->unique()->values())
            ->pluck('id', 'code');

        $ids = [];

        foreach ($this->items() as $item) {
            $measurementUnitId = $unitIds->get($item['unit']);

            if (! $measurementUnitId) {
                throw new RuntimeException('Satuan '.$item['unit'].' untuk '.$item['name'].' tidak tersedia.');
            }

            $ingredient = Ingredient::query()
                ->where('sppg_unit_id', $unitId)
                ->where('code', $item['code'])
                ->first();

            if (! $ingredient) {
                $ingredient = Ingredient::query()
                    ->where('sppg_unit_id', $unitId)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($item['name'])])
                    ->first();
            }

            if ($ingredient) {
                $ingredient->forceFill([
                    'measurement_unit_id' => $measurementUnitId,
                    'name' => $item['name'],
                    'is_active' => true,
                ]);

                if (blank($ingredient->category)) {
                    $ingredient->category = 'other';
                }

                if (blank($ingredient->description)) {
                    $ingredient->description = $this->sourceDescription($item);
                }

                $ingredient->save();
            } else {
                $ingredient = Ingredient::query()->create([
                    'sppg_unit_id' => $unitId,
                    'measurement_unit_id' => $measurementUnitId,
                    'code' => $item['code'],
                    'name' => $item['name'],
                    'category' => 'other',
                    'edible_portion_percent' => 100,
                    'loss_factor' => 1,
                    'rounding_mode' => 'up',
                    'nutrition_reference_grams' => 100,
                    'description' => $this->sourceDescription($item),
                    'is_active' => true,
                ]);
            }

            $ids[$item['code']] = (int) $ingredient->getKey();
        }

        return $ids;
    }

    private function sourceDescription(array $item): string
    {
        $parts = [
            'Master stok dari Laporan Stok Barang SPPG Nogotirto',
            'kode Excel '.$item['code'],
            'lokasi '.$item['location_name'],
        ];

        if (filled($item['size']) && $item['size'] !== '-') {
            $parts[] = 'ukuran '.$item['size'];
        }

        return implode(' | ', $parts);
    }

    private function ensureSeedPhoto(): string
    {
        $path = 'v3/warehouse/opening-stocks/seed-excel-2026-09-04.png';

        if (Storage::disk('public')->exists($path)) {
            return $path;
        }

        $logo = public_path('images/logo-bgn.png');

        if (is_file($logo)) {
            Storage::disk('public')->put($path, file_get_contents($logo));

            return $path;
        }

        // Fallback PNG 1x1 agar kolom photo_path tetap valid pada instalasi tanpa logo.
        Storage::disk('public')->put(
            $path,
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
        );

        return $path;
    }

    /**
     * Sumber: "Laporan Stok Barang SPPG Nogotirto (1).xlsx".
     * Kolom ending adalah Stok Akhir yang dijadikan saldo pembuka sistem.
     *
     * @return array<int, array{code:string,name:string,size:string,unit:string,ending:float|int,storage_type:string,location_name:string}>
     */
    private function items(): array
    {
        return [
            ['code' => 'H-001', 'name' => 'BAY LEAF', 'size' => '250 g', 'unit' => 'pcs', 'ending' => 1, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-002', 'name' => 'BEEF POWDER AJINOMOTO', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-003', 'name' => 'BEKING POWDER HERCULES', 'size' => '450 g', 'unit' => 'pcs', 'ending' => 1, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-004', 'name' => 'BERAS PREMIUM', 'size' => '25 Kg', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-005', 'name' => 'BUTTER ANCHOR', 'size' => '200 g', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-006', 'name' => 'CHICKEN POWDER AJINOMOTO', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-007', 'name' => 'EKOMIE', 'size' => '1 Karton x 6 Bag', 'unit' => 'bag', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-008', 'name' => 'GARAM DAUN', 'size' => '1 Ball X 40 Pcs', 'unit' => 'pcs', 'ending' => 37, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-009', 'name' => 'GARLIC POWDER KOEPOE', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-010', 'name' => 'GULA PASIR GUNUNG', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-011', 'name' => 'GULA PASIR KEMASAN', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 7, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-012', 'name' => 'HOHYONG', 'size' => '1 Kg', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-013', 'name' => 'JINTEN BUBUK YUTAKACHI', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-014', 'name' => 'KACANG GARUDA MERAH', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-015', 'name' => 'KECAP INGGRIS ABC', 'size' => '1 Lt', 'unit' => 'jirigen', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-016', 'name' => 'KECAP MANIS INDOFOOD', 'size' => '5,7 Kg X 1 Jirigen', 'unit' => 'jirigen', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-017', 'name' => 'KETUMBAR BUBUK', 'size' => '-', 'unit' => 'kg', 'ending' => 0.25, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-018', 'name' => 'KETUMBAR BUBUK DESAKU', 'size' => '250 g', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-019', 'name' => 'KRESE', 'size' => '-', 'unit' => 'kg', 'ending' => 0.87, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-020', 'name' => 'KUNYIT BUBUK', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-021', 'name' => 'KUNYIT BUBUK DESAKU', 'size' => '250 g', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-022', 'name' => 'LA FONTE PASTA SAUS BOLOGNESE', 'size' => '20 X 315 g', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-023', 'name' => 'LADAKU', 'size' => '250 g', 'unit' => 'pcs', 'ending' => 5, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-024', 'name' => 'MAGGI BUMBU SEASONING', 'size' => '200 Ml', 'unit' => 'botol', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-025', 'name' => 'MALKIST ORI (MERAH)', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-026', 'name' => 'MARGARIN FILMA', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-027', 'name' => 'MARGARIN', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-028', 'name' => 'MARIE REGAL', 'size' => '10 Pcs', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-029', 'name' => 'MASAKO AYAM', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 1, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-030', 'name' => 'MAYONAISE MAZZONI', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-031', 'name' => 'MAZZONI SAUS TERIYAKI', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-032', 'name' => 'MERICA BUBUK', 'size' => '-', 'unit' => 'kg', 'ending' => 0.5, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-033', 'name' => 'MICIN AJINOMOTO', 'size' => '1 Kg', 'unit' => 'kg', 'ending' => 2, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-034', 'name' => 'MINYAK GORENG SUNCO 2 Lt', 'size' => '1 Karton x 6', 'unit' => 'pcs', 'ending' => 18, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-035', 'name' => 'MINYAK WIJEN', 'size' => '-', 'unit' => 'botol', 'ending' => 1, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-036', 'name' => 'PALA BUBUK SACHET', 'size' => '12 Pcs/Renceng', 'unit' => 'pcs', 'ending' => 24, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-037', 'name' => 'PALA BUBUK', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-038', 'name' => 'PARSLEY', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-039', 'name' => 'ROMA MALKIST COKLAT', 'size' => '10 Pcs', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-040', 'name' => 'ROMA SARI GANDUM', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-041', 'name' => 'SANTAN SUNKARA 1 Lt', 'size' => '1 Lt', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-042', 'name' => 'SASA SAUS PEDAS', 'size' => '1 Pack X 48 Pcs', 'unit' => 'pack', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-043', 'name' => 'SASA SAUS TOMAT', 'size' => '1 Pack X 48 Pcs', 'unit' => 'pack', 'ending' => 1, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-044', 'name' => 'SAUS BBQ DELMONTE', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 17, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-045', 'name' => 'SAUS BBQ MAZZONI', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-046', 'name' => 'SAUS TOMAT DELMONTE', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-047', 'name' => 'SAUSTIRAM MAGGI', 'size' => '350 g', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-048', 'name' => 'SODA KUE', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-049', 'name' => 'SODIUM', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-050', 'name' => 'SPAGHETI SEDANI', 'size' => '10 x 1Kg', 'unit' => 'pcs', 'ending' => 110, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-051', 'name' => 'SUSU CLEVO FULL CREAM', 'size' => '40 X 115 ml', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-052', 'name' => 'SUSU DIAMOND FULL CREAM', 'size' => '40 x 125 ml', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-053', 'name' => 'SUSU INDOMILK PLAIN UHT', 'size' => '40 X 115 ml', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-054', 'name' => 'SUSU MILK LIFE CREAMY VANILLA', 'size' => '40 x 115 ml', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-055', 'name' => 'SUSU MILK LIFE FULL CREAM', 'size' => '40 x 115 ml', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-056', 'name' => 'SUSU ULTRA MIMI FULL CREAM', 'size' => '40 x 125 ml', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-057', 'name' => 'SUSU ULTRAMILK FULL CREAM', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-058', 'name' => 'TEPUNG BERAS ROSE BRAND', 'size' => '20 x 500 gr', 'unit' => 'pcs', 'ending' => 16, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-059', 'name' => 'TEPUNG BERAS SANIA', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-060', 'name' => 'TEPUNG CUSTAR/CUSTAR POWDER', 'size' => '-', 'unit' => 'pcs', 'ending' => 1, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-061', 'name' => 'TEPUNG MAIZENA HONIKA', 'size' => '20 x 1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-062', 'name' => 'TEPUNG ROTI J FOOD/PANIR', 'size' => '-', 'unit' => 'kg', 'ending' => 13, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-063', 'name' => 'TEPUNG TAPIOKA ROSEBRAND', 'size' => '-', 'unit' => 'kg', 'ending' => 7, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-064', 'name' => 'TEPUNG TERIGU SANIA', 'size' => '10 X 1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-065', 'name' => 'TEPUNG TERIGU SEGITIGA BIRU', 'size' => '1 Karung X 25 Kg', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-066', 'name' => 'WIJEN', 'size' => '-', 'unit' => 'kg', 'ending' => 0.25, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-067', 'name' => 'WIJEN SANGRAI MAESTRO', 'size' => '1 Karton x 40 Pcs', 'unit' => 'pcs', 'ending' => 30, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-068', 'name' => 'KECAP MANIS ABC', 'size' => '6 Kg X 1 Jirigen', 'unit' => 'jirigen', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-069', 'name' => 'MICIN AJINOMOTO PLUS', 'size' => '1 Kg', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-070', 'name' => 'CUKA MAKAN DAXI', 'size' => '650 ml', 'unit' => 'kg', 'ending' => 1, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-071', 'name' => 'KEJU PROCHIZ', 'size' => '1 PACK X 2 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-072', 'name' => 'SUSU KENTAL MANIS', 'size' => '2,5 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-073', 'name' => 'SUSU CIMORY FULL CREAM', 'size' => '40 x 125 ml', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-074', 'name' => 'MAKARONI SEDANI', 'size' => '10 X 1 Kg', 'unit' => 'pcs', 'ending' => 7, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-075', 'name' => 'KEJU EMINA', 'size' => '2 Kg', 'unit' => 'pack', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-076', 'name' => 'BASIL YUTAKACHI', 'size' => '250 gr', 'unit' => 'pcs', 'ending' => 1, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-077', 'name' => 'BUBUK OREGANO', 'size' => '250 gr', 'unit' => 'pack', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-078', 'name' => 'KNOOR DEMIGRACE SAUCE', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-079', 'name' => 'SMOKE LIQUID', 'size' => '1 Lt', 'unit' => 'botol', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-080', 'name' => 'SAORI SAUS TERIYAKI', 'size' => '1 Dus X 6', 'unit' => 'botol', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-081', 'name' => 'SAUS TIRAM LEE KUN KALENG', 'size' => '1 Kaleng X 2,2 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-082', 'name' => 'SAORI SAUS TIRAM', 'size' => '1 Lt', 'unit' => 'botol', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-083', 'name' => 'MINYAK GORENG SANIA 2 Lt', 'size' => '1 Karton x 6', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-084', 'name' => 'SASA SANTAN', 'size' => '1 Lt', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-085', 'name' => 'SAUS BBQ MC LEWIS', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-086', 'name' => 'MAMASUKA SAUS BOLOGNESE', 'size' => '24 X 315 g', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-087', 'name' => 'BIHUN JAGUNG', 'size' => '300 g', 'unit' => 'pack', 'ending' => 10, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-088', 'name' => 'KECAP ASIN INDOFOOD', 'size' => '140 ml', 'unit' => 'botol', 'ending' => 4, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-089', 'name' => 'KEJU PROCHIZ SLICE GOLD', 'size' => '1 DUS X 192 Pcs', 'unit' => 'pcs', 'ending' => 90, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-090', 'name' => 'MINYAK GORENG SOVIA 2 Lt', 'size' => '1 Karton x 6', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-091', 'name' => 'ABON AYAM', 'size' => '-', 'unit' => 'kg', 'ending' => 2, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-092', 'name' => 'BRAMBANG GORENG', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-093', 'name' => 'GULA JAWA', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-094', 'name' => 'WIJEN YUTAKACHI', 'size' => '1 Kg', 'unit' => 'kg', 'ending' => 1, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-095', 'name' => 'KACANG HIJAU', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-096', 'name' => 'FIBER CREAM', 'size' => '1 Dus X 12 Pcs', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-097', 'name' => 'COOKING CREAM', 'size' => '1 Lt', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-098', 'name' => 'TEPUNG MAIZENA BOLA DELI 250 g', 'size' => '250 g', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-099', 'name' => 'HOUSE KARI ALA JEPANG', 'size' => '80 g', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-100', 'name' => 'BUMBU KACANG SIAP PAKAI', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-101', 'name' => 'ROTI HOTDOG', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-102', 'name' => 'BUMBUKU KETUMBAR BUBUK', 'size' => '250 g/Pcs', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-103', 'name' => 'TEPUNG MAIZENA BOLA DELI', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 3, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-104', 'name' => 'AONORI', 'size' => '250 g/Pcs', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-105', 'name' => 'SAUS MENTAI PRIMA AGUNG', 'size' => '-', 'unit' => 'kg', 'ending' => 5, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-106', 'name' => 'IKAN TERI MEDAN', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-107', 'name' => 'ROTI BURGER', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-108', 'name' => 'NORI TABUR', 'size' => '-', 'unit' => 'kg', 'ending' => 0.4, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-109', 'name' => 'L&P SAUCE', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-110', 'name' => 'SAUS CARBONARA', 'size' => '250 g', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-111', 'name' => 'MUSTARD MAESTRO', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-112', 'name' => 'MADU MURNI', 'size' => '600 Ml', 'unit' => 'botol', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-113', 'name' => 'NGOHIONG YUTAKKACHI', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-114', 'name' => 'MAYONAISE MAESTRO', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-115', 'name' => 'BUMBUKU JINTEN BUBUK 200 gr', 'size' => '1 Renceng x 12 Scht', 'unit' => 'pcs', 'ending' => 33, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-116', 'name' => 'KECAP ASIN ABC', 'size' => '620 ml', 'unit' => 'botol', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-117', 'name' => 'GARLIC POWDER KOEPOE 1 Kg', 'size' => '1 Kg', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-118', 'name' => 'NORI TABUR ORIGINAL', 'size' => '1 Pcs/60 g', 'unit' => 'pcs', 'ending' => 7, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-119', 'name' => 'NORI SHEAT', 'size' => '50/Pcs', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-120', 'name' => 'GARAM DAUN 500 g', 'size' => '1 Ball X 20 Pcs', 'unit' => 'pcs', 'ending' => 20, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'H-121', 'name' => 'KECAP ASIN ABC 133 ml', 'size' => '133 ml', 'unit' => 'botol', 'ending' => 0, 'storage_type' => 'dry', 'location_name' => 'Gudang Kering'],
            ['code' => 'B-001', 'name' => 'ANGGUR', 'size' => '-', 'unit' => 'box', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-002', 'name' => 'ANGGUR/Kg', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-003', 'name' => 'APEL', 'size' => '-', 'unit' => 'box', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-004', 'name' => 'ASAM JAWA GUNUNG', 'size' => '-', 'unit' => 'kg', 'ending' => 1, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-005', 'name' => 'AYAM DADA FILLET DADU', 'size' => '-', 'unit' => 'pack', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-006', 'name' => 'AYAM DADA FILLET SLICE', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-007', 'name' => 'AYAM GILING KASAR', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-008', 'name' => 'AYAM PAHA ATAS TANPA TUNGGIR', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-009', 'name' => 'BASO SAPI', 'size' => 'Isi 50/Pack', 'unit' => 'pack', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-010', 'name' => 'BAWANG BOMBAY', 'size' => '-', 'unit' => 'kg', 'ending' => 1, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-011', 'name' => 'BAWANG MERAH KUPAS', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-012', 'name' => 'BAWANG PUTIH KUPAS', 'size' => '-', 'unit' => 'kg', 'ending' => 1, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-013', 'name' => 'BAYAM BUBUT', 'size' => '-', 'unit' => 'ikat', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-014', 'name' => 'BROKOLI', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-015', 'name' => 'BUAH NAGA', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-016', 'name' => 'BUNCIS', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-017', 'name' => 'CEKER AYAM', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-018', 'name' => 'CHIKEN CHEESE CND 50/PACK', 'size' => '-', 'unit' => 'pack', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-019', 'name' => 'DAUN BAWANG', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-020', 'name' => 'DAUN JERUK', 'size' => '-', 'unit' => 'kg', 'ending' => 0.4, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-021', 'name' => 'DAUN KEMANGI', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-022', 'name' => 'DAUN PANDAN', 'size' => '-', 'unit' => 'ikat', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-023', 'name' => 'DAUN PISANG', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-024', 'name' => 'DAUN SALAM', 'size' => '200 gr', 'unit' => 'ikat', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-025', 'name' => 'DIMSUM AYAM', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-026', 'name' => 'DORI FILLET', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-027', 'name' => 'EBI FURAI CND', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-028', 'name' => 'EDAMAME', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-029', 'name' => 'GALANTIN', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-030', 'name' => 'JAGUNG PIPIL', 'size' => '1 Kg X 1 Pack', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-031', 'name' => 'JAHE GAJAH', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-032', 'name' => 'JAMUR KANCING', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-033', 'name' => 'JAMUR KUPING', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-034', 'name' => 'JERUK JAMBI', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-035', 'name' => 'JERUK MEDAN/BERASTAGI', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-036', 'name' => 'JERUK NIPIS', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-037', 'name' => 'JERUK SIAM MADU', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-038', 'name' => 'KACANG PANJANG', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-039', 'name' => 'KACANG POLONG FROZEN', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-040', 'name' => 'KAKI NAGA', 'size' => 'Isi 30/Pack', 'unit' => 'pack', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-041', 'name' => 'KELAPA PARUT', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-042', 'name' => 'KELENGKENG', 'size' => '-', 'unit' => 'box', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-043', 'name' => 'KEMBANG KOL', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-044', 'name' => 'KEMIRI', 'size' => '-', 'unit' => 'kg', 'ending' => 1, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-045', 'name' => 'KENCUR', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-046', 'name' => 'KENTANG CRINKLE', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-047', 'name' => 'KENTANG CRINKLE AVIKO', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-048', 'name' => 'KENTANG CRINKLE HOME', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-049', 'name' => 'KENTANG SEGAR', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-050', 'name' => 'KENTANG WEDGES', 'size' => '1 Kg/Pack', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-051', 'name' => 'KOL UNGU', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-052', 'name' => 'KOL/KUBIS', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-053', 'name' => 'KULIT DIMSUM', 'size' => 'Isi 50/Pack', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-054', 'name' => 'KUNYIT SEGAR', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-055', 'name' => 'LABU SIAM', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-056', 'name' => 'LECI MERAH', 'size' => '-', 'unit' => 'box', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-057', 'name' => 'LELE FILLET', 'size' => '-', 'unit' => 'slice', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-058', 'name' => 'LEMON', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-059', 'name' => 'LENGKUAS', 'size' => '-', 'unit' => 'kg', 'ending' => 0.8, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-060', 'name' => 'MELON', 'size' => '-', 'unit' => 'pcs', 'ending' => 16, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-061', 'name' => 'PAKCOY', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-062', 'name' => 'PISANG AMBON', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-063', 'name' => 'PUTREN', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-064', 'name' => 'ROLADE AYAM', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-065', 'name' => 'ROLADE SAPI', 'size' => 'Isi 50/Pack', 'unit' => 'pack', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-066', 'name' => 'SATE LILIT', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-067', 'name' => 'SATE REMPAH', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-068', 'name' => 'SAWI PUTIH', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-069', 'name' => 'SELADA AIR/JAMBAK', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-070', 'name' => 'SELADA HIDRO', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-071', 'name' => 'SELEDRI', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-072', 'name' => 'SEMPOL AYAM', 'size' => 'Isi 50/Pack', 'unit' => 'pack', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-073', 'name' => 'SERAI', 'size' => '-', 'unit' => 'kg', 'ending' => 0.75, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-074', 'name' => 'SOSIS SOLO', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-075', 'name' => 'TAHU BAKSO', 'size' => '1 Pack X 10 Pcs', 'unit' => 'pack', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-076', 'name' => 'TAHU MAGEL', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-077', 'name' => 'TAHU PONG', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-078', 'name' => 'TAHU PUTIH', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-079', 'name' => 'TELOR OMEGA KUKUS', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-080', 'name' => 'TELUR AYAM', 'size' => '-', 'unit' => 'kg', 'ending' => 19, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-081', 'name' => 'TELUR AYAM KUPAS', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-082', 'name' => 'TELUR PUYUH', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-083', 'name' => 'TEMPE A-ZAKI', 'size' => '1 Papan/500 g', 'unit' => 'papan', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-084', 'name' => 'TEMPE MUCHLAR', 'size' => '500 g', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-085', 'name' => 'TEMU KUNCI', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-086', 'name' => 'TIMUN BESAR', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-087', 'name' => 'TOMAT SAYUR', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-088', 'name' => 'WORTEL LOKAL SEDANG', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-089', 'name' => 'TAHU BULAT', 'size' => '-', 'unit' => 'pcs', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-090', 'name' => 'UDANG', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-091', 'name' => 'NANAS', 'size' => '-', 'unit' => 'biji', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-092', 'name' => 'KIWI', 'size' => '-', 'unit' => 'box', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-093', 'name' => 'TOMAT HIJAU', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
            ['code' => 'B-094', 'name' => 'BELIMBING WULUH', 'size' => '-', 'unit' => 'kg', 'ending' => 0, 'storage_type' => 'wet', 'location_name' => 'Gudang Basah & Freezer'],
        ];
    }
}
