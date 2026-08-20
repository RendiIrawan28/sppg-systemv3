<?php

namespace App\Support\Mobile;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MobileOperationalRecordTransformer
{
    public function __construct(
        private readonly MobileWorkspaceRegistry $registry,
    ) {}

    /** @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function summary(string $slug, array $definition, Model $record): array
    {
        return [
            'id' => $record->getKey(),
            'number' => (string) $record->getAttribute($definition['number']),
            'date' => $this->dateValue($record->getAttribute($definition['date'])),
            'title' => $this->title($slug, $record),
            'subtitle' => $this->subtitle($slug, $record),
            'state' => $this->rawValue($record->getAttribute('state')),
            'state_label' => $this->displayValue($record->getAttribute('state')),
            'status' => $this->rawValue($record->getAttribute('status')),
            'status_label' => $this->displayValue($record->getAttribute('status')),
            'is_history' => $this->isHistory($slug, $record),
            'assignee' => $this->assignee($slug, $record),
            'metrics' => $this->metrics($slug, $record),
        ];
    }

    private function isHistory(string $slug, Model $record): bool
    {
        $state = strtolower((string) ($this->rawValue($record->getAttribute('state')) ?? ''));
        $status = strtolower((string) ($this->rawValue($record->getAttribute('status')) ?? ''));

        $terminalStates = match ($slug) {
            'distribusi' => ['returned', 'cancelled'],
            'pencucian' => ['completed', 'ready', 'cancelled'],
            'pengolahan', 'pemorsian', 'persiapan' => ['completed', 'cancelled'],
            'pengambilan-ompreng' => ['returned', 'completed', 'cancelled'],
            'pengambilan-ompreng-tugas' => ['collected', 'completed', 'cancelled'],
            default => [
                'completed', 'finished', 'returned', 'received', 'verified', 'approved',
                'resolved', 'closed', 'collected', 'cancelled', 'expired', 'rejected',
                'submitted', 'delivered', 'failed', 'ready',
            ],
        };
        $terminalStatuses = [
            'completed', 'finished', 'returned', 'received', 'verified', 'approved',
            'resolved', 'closed', 'collected', 'cancelled', 'expired', 'rejected',
            'submitted', 'delivered', 'failed', 'ready',
        ];

        return in_array($state, $terminalStates, true)
            || in_array($status, $terminalStatuses, true);
    }

    /** @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function detail(string $slug, array $definition, Model $record, int $unitId): array
    {
        return [
            ...$this->summary($slug, $definition, $record),
            'fields' => $this->fields($record, $definition['fields'] ?? [], $unitId),
            'sections' => collect($definition['relations'] ?? [])->map(function (array $relation, string $name) use ($record, $unitId): array {
                $value = $record->getRelation($name);
                $items = $value instanceof Collection || $value instanceof EloquentCollection
                    ? $value
                    : collect($value ? [$value] : []);

                return [
                    'key' => $name,
                    'title' => $relation['label'],
                    'items' => $items->map(fn (Model $item): array => [
                        'id' => $item->getKey(),
                        'title' => $this->relationTitle($item, $relation['fields']),
                        'fields' => $this->fields($item, $relation['fields'], $unitId),
                    ])->values(),
                ];
            })->values(),
        ];
    }

    /** @param array<int, array<string, mixed>> $fields */
    private function fields(Model $record, array $fields, int $unitId): array
    {
        return collect($fields)->map(function (array $field) use ($record, $unitId): array {
            $value = $record->getAttribute($field['name']);
            $display = $this->formatField($record, $value, $field, $unitId);

            return [
                'key' => $field['name'],
                'label' => $field['label'],
                'value' => $display,
                'type' => $field['type'] ?? 'text',
                'file_url' => ($field['type'] ?? null) === 'file' && filled($value)
                    ? url(Storage::disk('public')->url($value))
                    : null,
            ];
        })->filter(fn (array $field): bool => filled($field['value']))->values()->all();
    }

    private function formatField(Model $record, mixed $value, array $field, int $unitId): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (($field['name'] ?? null) === 'ingredient_id' && method_exists($record, 'ingredient')) {
            $ingredient = $record->relationLoaded('ingredient')
                ? $record->getRelation('ingredient')
                : $record->ingredient()->first();

            return filled($ingredient?->name) ? (string) $ingredient->name : $this->displayValue($value);
        }


        if (($field['name'] ?? null) === 'non_food_item_id' && method_exists($record, 'nonFoodItem')) {
            $item = $record->relationLoaded('nonFoodItem')
                ? $record->getRelation('nonFoodItem')
                : $record->nonFoodItem()->first();

            return filled($item?->name) ? (string) $item->name : $this->displayValue($value);
        }

        if (($field['type'] ?? null) === 'select' && isset($field['options'])) {
            $options = $this->registry->options($field['options'], $unitId);
            $raw = $this->rawValue($value);

            return (string) ($options[$raw] ?? $this->displayValue($value));
        }

        return match ($field['type'] ?? 'text') {
            'date' => $this->dateValue($value),
            'datetime' => $value instanceof \DateTimeInterface
                ? Carbon::instance($value)->timezone(config('app.timezone'))->format('d M Y, H:i')
                : Carbon::parse($value)->format('d M Y, H:i'),
            'boolean' => (bool) $value ? 'Ya' : 'Tidak',
            'file' => filled($value) ? 'Dokumen tersedia' : null,
            default => $this->displayValue($value),
        };
    }

    private function title(string $slug, Model $record): string
    {
        if (in_array($slug, ['gudang-stok', 'gudang-stok-non-pangan'], true)) {
            $stockItem = $slug === 'gudang-stok-non-pangan'
                ? ($record->relationLoaded('nonFoodItem') ? $record->getRelation('nonFoodItem') : $record->nonFoodItem()->first())
                : ($record->relationLoaded('ingredient') ? $record->getRelation('ingredient') : $record->ingredient()->first());
            if (filled($stockItem?->name)) {
                return (string) $stockItem->name;
            }
        }

        $candidates = match ($slug) {
            'gudang', 'gudang-non-pangan' => ['received_by_name'],
            'gudang-stok-awal', 'gudang-stok-awal-non-pangan' => ['opening_number'],
            'gudang-stok', 'gudang-stok-non-pangan' => ['location_name', 'lot_number'],
            'gudang-penyesuaian', 'gudang-penyesuaian-non-pangan' => ['adjustment_number'],
            'gudang-pengambilan', 'gudang-pengambilan-non-pangan', 'pengambilan-non-pangan' => ['purpose_reference', 'reference_number_snapshot'],
            'gudang-retur', 'gudang-retur-pengolahan' => ['ingredient_name_snapshot'],
            'persiapan' => ['purpose_reference'],
            'hasil-persiapan', 'hasil-persiapan-pengolahan', 'hasil-persiapan-pemorsian' => ['output_name', 'source_ingredient_name_snapshot'],
            'pengambilan-ompreng-tugas' => ['destination_name'],
            'pengambilan-ompreng' => ['run_number', 'driver_name_snapshot'],
            'ba-limbah-persiapan', 'ba-limbah-pencucian', 'ba-limbah-kebersihan' => ['source_reference', 'report_number'],
            'lapangan-insiden' => ['title'],
            'lapangan-laporan' => ['operational_summary', 'report_number'],
            'keamanan' => ['officer_name_snapshot'],
            'pengolahan' => ['product_name', 'menu_name_snapshot'],
            'pemorsian' => ['menu_name_snapshot'],
            'distribusi' => ['route_name', 'menu_name_snapshot'],
            'pencucian' => ['route_name', 'menu_name_snapshot'],
            'kebersihan' => ['before_condition'],
            default => [],
        };

        foreach ($candidates as $field) {
            if (filled($record->getAttribute($field))) {
                return (string) $record->getAttribute($field);
            }
        }

        return 'Pekerjaan '.$this->dateValue($record->getAttribute('created_at'));
    }

    private function subtitle(string $slug, Model $record): ?string
    {
        return match ($slug) {
            'gudang', 'gudang-non-pangan' => filled($record->getAttribute('notes')) ? Str::limit((string) $record->getAttribute('notes'), 90) : null,
            'gudang-stok-awal', 'gudang-stok-awal-non-pangan' => filled($record->getAttribute('notes')) ? Str::limit((string) $record->getAttribute('notes'), 90) : 'Stok langsung aktif',
            'gudang-stok', 'gudang-stok-non-pangan' => filled($record->getAttribute('storage_type')) ? Str::headline((string) $record->getAttribute('storage_type')) : null,
            'gudang-penyesuaian', 'gudang-penyesuaian-non-pangan' => filled($record->getAttribute('reason')) ? Str::limit((string) $record->getAttribute('reason'), 90) : null,
            'gudang-pengambilan', 'gudang-pengambilan-non-pangan', 'pengambilan-non-pangan' => filled($record->getAttribute('division_code')) ? 'Divisi '.Str::headline((string) $record->getAttribute('division_code')) : null,
            'gudang-retur', 'gudang-retur-pengolahan' => filled($record->getAttribute('reason')) ? Str::limit((string) $record->getAttribute('reason'), 90) : null,
            'persiapan' => filled($record->getAttribute('notes')) ? Str::limit((string) $record->getAttribute('notes'), 90) : null,
            'hasil-persiapan', 'hasil-persiapan-pengolahan', 'hasil-persiapan-pemorsian' => filled($record->getAttribute('storage_location')) ? 'Disimpan di '.$record->getAttribute('storage_location') : null,
            'pengambilan-ompreng-tugas' => filled($record->getAttribute('address')) ? Str::limit((string) $record->getAttribute('address'), 90) : null,
            'pengambilan-ompreng' => filled($record->getAttribute('vehicle_plate')) ? 'Kendaraan '.$record->getAttribute('vehicle_plate') : null,
            'ba-limbah-persiapan', 'ba-limbah-pencucian', 'ba-limbah-kebersihan' => filled($record->getAttribute('second_party_name')) ? 'Diserahkan kepada '.$record->getAttribute('second_party_name') : null,
            'lapangan-insiden' => filled($record->getAttribute('location')) ? (string) $record->getAttribute('location') : null,
            'lapangan-laporan' => 'Ringkasan otomatis kegiatan lapangan',
            'keamanan' => filled($record->getAttribute('scheduled_end_at')) ? 'Shift keamanan 12 jam' : null,
            'pengolahan' => filled($record->getAttribute('menu_name_snapshot')) ? (string) $record->getAttribute('menu_name_snapshot') : null,
            'distribusi' => collect([
                $record->getAttribute('menu_name_snapshot'),
                filled($record->getAttribute('vehicle_plate')) ? 'Kendaraan '.$record->getAttribute('vehicle_plate') : null,
            ])->filter()->implode(' · ') ?: null,
            'pencucian' => collect([
                $record->getAttribute('menu_name_snapshot'),
                filled($record->getAttribute('petugas_name_snapshot')) ? 'Petugas '.$record->getAttribute('petugas_name_snapshot') : null,
            ])->filter()->implode(' · ') ?: null,
            'kebersihan' => filled($record->getAttribute('shift')) ? 'Shift '.Str::title((string) $record->getAttribute('shift')) : null,
            default => null,
        };
    }

    private function assignee(string $slug, Model $record): ?string
    {
        foreach (['petugas_name_snapshot', 'driver_name_snapshot', 'driver_name', 'received_by_name'] as $field) {
            if (filled($record->getAttribute($field))) {
                return (string) $record->getAttribute($field);
            }
        }

        return null;
    }

    private function metrics(string $slug, Model $record): array
    {
        $fields = match ($slug) {
            'gudang', 'gudang-non-pangan' => [['items_count', 'Barang']],
            'gudang-stok-awal', 'gudang-stok-awal-non-pangan' => [['items_count', 'Barang']],
            'gudang-stok', 'gudang-stok-non-pangan' => [['balance_quantity', 'Saldo'], ['movements_count', 'Mutasi']],
            'gudang-penyesuaian', 'gudang-penyesuaian-non-pangan' => [['system_quantity', 'Saldo sistem'], ['actual_quantity', 'Saldo aktual']],
            'gudang-pengambilan', 'gudang-pengambilan-non-pangan', 'pengambilan-non-pangan' => [['items_count', 'Barang']],
            'gudang-retur', 'gudang-retur-pengolahan' => [['requested_quantity', 'Diajukan'], ['actual_quantity', 'Aktual']],
            'persiapan' => [['items_count', 'Bahan']],
            'hasil-persiapan', 'hasil-persiapan-pengolahan', 'hasil-persiapan-pemorsian' => [['available_quantity', 'Tersedia'], ['withdrawals_count', 'Pengambilan']],
            'pengambilan-ompreng-tugas' => [['target_containers', 'Target'], ['remaining_containers', 'Sisa']],
            'pengambilan-ompreng' => [['total_collected', 'Diambil'], ['items_count', 'Tujuan']],
            'ba-limbah-persiapan', 'ba-limbah-pencucian', 'ba-limbah-kebersihan' => [['items_count', 'Jenis limbah']],
            'lapangan-insiden' => [],
            'lapangan-laporan' => [['completed_destinations', 'Tujuan selesai'], ['delivered_portions', 'Porsi terkirim']],
            'keamanan' => [['reports_count', 'Laporan'], ['reports_expected', 'Target']],
            'pengolahan' => [['target_output_quantity', 'Target'], ['actual_output_quantity', 'Realisasi']],
            'pemorsian' => [['target_small_portions', 'Target kecil'], ['target_large_portions', 'Target besar']],
            'distribusi' => [['planned_small_portions', 'Porsi kecil'], ['planned_large_portions', 'Porsi besar']],
            'pencucian' => [['expected_containers', 'Diharapkan'], ['clean_containers', 'Bersih']],
            'kebersihan' => [['checklist_items_count', 'Checklist'], ['findings_count', 'Temuan']],
            default => [],
        };

        return collect($fields)->map(function (array $field) use ($record, $slug): array {
            $value = (string) ($record->getAttribute($field[0]) ?? 0);
            if (in_array($slug, ['gudang-stok', 'gudang-stok-non-pangan', 'gudang-penyesuaian', 'gudang-penyesuaian-non-pangan', 'gudang-retur', 'gudang-retur-pengolahan'], true)
                && filled($record->getAttribute('unit_snapshot'))) {
                $value .= ' '.$record->getAttribute('unit_snapshot');
            }

            return ['label' => $field[1], 'value' => $value];
        })->all();
    }

    private function relationTitle(Model $record, array $fields): string
    {
        foreach ($fields as $field) {
            $value = $record->getAttribute($field['name']);
            if (filled($value) && ! is_numeric($value) && ! $value instanceof \BackedEnum) {
                return Str::limit((string) $value, 80);
            }
        }

        return 'Item #'.$record->getKey();
    }

    private function rawValue(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : ($value !== null ? (string) $value : null);
    }

    private function displayValue(mixed $value): ?string
    {
        if ($value instanceof \BackedEnum) {
            return method_exists($value, 'label') ? $value->label() : Str::headline((string) $value->value);
        }
        if (is_array($value)) {
            return collect($value)->filter()->implode(', ');
        }

        return $value !== null ? (string) $value : null;
    }

    private function dateValue(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return $value instanceof \DateTimeInterface
            ? Carbon::instance($value)->toDateString()
            : Carbon::parse($value)->toDateString();
    }
}
