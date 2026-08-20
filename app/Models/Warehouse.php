<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    public const TYPE_FOOD = 'food';
    public const TYPE_NON_FOOD = 'non_food';
    public const CODE_FOOD = 'FOOD';
    public const CODE_NON_FOOD = 'NON_FOOD';

    protected $fillable = ['sppg_unit_id', 'code', 'name', 'type', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function sppgUnit(): BelongsTo { return $this->belongsTo(SppgUnit::class); }
    public function inventoryLots(): HasMany { return $this->hasMany(InventoryLot::class); }
    public function movements(): HasMany { return $this->hasMany(StockMovement::class); }

    public static function forUnit(int $unitId, string $type): self
    {
        $warehouse = self::query()
            ->where('sppg_unit_id', $unitId)
            ->where('type', $type)
            ->first();

        if (! $warehouse) {
            $defaults = match ($type) {
                self::TYPE_FOOD => ['code' => self::CODE_FOOD, 'name' => 'Gudang Pangan'],
                self::TYPE_NON_FOOD => ['code' => self::CODE_NON_FOOD, 'name' => 'Gudang Non-Pangan'],
                default => throw new \InvalidArgumentException('Jenis Gudang tidak valid.'),
            };
            $warehouse = self::query()->firstOrCreate(
                ['sppg_unit_id' => $unitId, 'code' => $defaults['code']],
                ['name' => $defaults['name'], 'type' => $type, 'is_active' => true],
            );
        }

        abort_unless($warehouse->is_active, 422, 'Gudang sedang tidak aktif.');

        return $warehouse;
    }
}
