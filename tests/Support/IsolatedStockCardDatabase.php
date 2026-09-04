<?php

namespace Tests\Support;

use App\Models\Ingredient;
use App\Models\InventoryLot;
use App\Models\MeasurementUnit;
use App\Models\NonFoodItem;
use App\Models\OpeningStock;
use App\Models\OpeningStockItem;
use App\Models\SppgUnit;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Models\StockReceiptItemPhoto;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseWithdrawal;
use App\Models\WarehouseWithdrawalItem;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait IsolatedStockCardDatabase
{
    public function setUpIsolatedStockCardDatabase(): void
    {
        config(['database.default' => 'stock_card_memory', 'database.connections' => [
            'stock_card_memory' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        ], 'cache.default' => 'array', 'session.driver' => 'array', 'sppg.unit_id' => null, 'firebase.enabled' => false]);
        DB::purge('stock_card_memory');
        if (DB::connection()->getDriverName() !== 'sqlite' || DB::connection()->getDatabaseName() !== ':memory:') {
            throw new \RuntimeException('Stock card tests must only use isolated memory.');
        }
        (require database_path('migrations/0001_01_01_000000_create_foundation_tables.php'))->up();
        (require database_path('migrations/0101_01_01_000000_create_permission_tables.php'))->up();
        // Minimal stock schema, created only in memory; no application migration/reset is run.
        foreach ([
            SppgUnit::class, Ingredient::class, MeasurementUnit::class,
            Warehouse::class, NonFoodItem::class, Supplier::class,
            StockReceipt::class, StockReceiptItem::class, StockReceiptItemPhoto::class,
            InventoryLot::class, StockMovement::class, StockAdjustment::class,
            OpeningStock::class, OpeningStockItem::class,
            WarehouseWithdrawal::class, WarehouseWithdrawalItem::class,
        ] as $class) {
            $model = new $class;
            Schema::create($model->getTable(), function (Blueprint $t) use ($model): void {
                $t->id();
                foreach ($model->getFillable() as $column) {
                    if (in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                        continue;
                    }
                    if (str_starts_with($column, 'is_') || str_starts_with($column, 'tracks_')) {
                        $t->boolean($column)->nullable();
                    } elseif (str_ends_with($column, '_id') || in_array($column, ['created_by', 'received_by', 'uploaded_by', 'verified_by'])) {
                        $t->unsignedBigInteger($column)->nullable();
                    } elseif (preg_match('/quantity|stock$|grams|factor|percent|price|temperature/', $column)) {
                        $t->decimal($column, 18, 6)->nullable();
                    } else {
                        $t->text($column)->nullable();
                    }
                }
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    public function tearDownIsolatedStockCardDatabase(): void
    {
        DB::purge('stock_card_memory');
    }
}
