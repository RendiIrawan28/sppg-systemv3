<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_receipts', function (Blueprint $table): void {
            $table->unsignedBigInteger('supplier_id')->nullable()->after('procurement_request_id')->index();
            $table->string('documentation_path')->nullable()->after('notes');
        });

        $drafts = DB::table('stock_receipts')
            ->where('status', 'draft')
            ->orderBy('id')
            ->get();

        foreach ($drafts as $receipt) {
            $supplierIds = DB::table('stock_receipt_items')
                ->where('stock_receipt_id', $receipt->id)
                ->whereNotNull('supplier_id')
                ->distinct()
                ->orderBy('supplier_id')
                ->pluck('supplier_id');

            if ($supplierIds->isEmpty()) {
                continue;
            }

            DB::table('stock_receipts')
                ->where('id', $receipt->id)
                ->update(['supplier_id' => $supplierIds->first()]);

            foreach ($supplierIds->skip(1) as $supplierId) {
                $newReceiptId = DB::table('stock_receipts')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'sppg_unit_id' => $receipt->sppg_unit_id,
                    'procurement_request_id' => $receipt->procurement_request_id,
                    'supplier_id' => $supplierId,
                    'receipt_number' => $this->nextReceiptNumber((int) $receipt->sppg_unit_id, (string) $receipt->receipt_date),
                    'receipt_date' => $receipt->receipt_date,
                    'status' => $receipt->status,
                    'received_by_name' => $receipt->received_by_name,
                    'notes' => $receipt->notes,
                    'created_by' => $receipt->created_by,
                    'received_by' => $receipt->received_by,
                    'received_at' => $receipt->received_at,
                    'created_at' => $receipt->created_at,
                    'updated_at' => $receipt->updated_at,
                ]);

                DB::table('stock_receipt_items')
                    ->where('stock_receipt_id', $receipt->id)
                    ->where('supplier_id', $supplierId)
                    ->update(['stock_receipt_id' => $newReceiptId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('stock_receipts', function (Blueprint $table): void {
            $table->dropIndex(['supplier_id']);
            $table->dropColumn(['supplier_id', 'documentation_path']);
        });
    }

    private function nextReceiptNumber(int $unitId, string $receiptDate): string
    {
        $year = substr($receiptDate, 0, 4) ?: now()->format('Y');
        $sequence = DB::table('stock_receipts')
            ->where('sppg_unit_id', $unitId)
            ->whereYear('receipt_date', $year)
            ->count() + 1;

        do {
            $number = sprintf('PBM/%s/%04d', $year, $sequence++);
        } while (DB::table('stock_receipts')->where('receipt_number', $number)->exists());

        return $number;
    }
};
