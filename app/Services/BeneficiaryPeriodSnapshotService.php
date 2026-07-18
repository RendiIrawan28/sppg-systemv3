<?php

namespace App\Services;

use App\Models\Beneficiary;
use App\Models\BeneficiaryCategory;
use App\Models\BeneficiaryPeriod;
use App\Models\BeneficiaryPeriodDestination;
use App\Models\BeneficiaryPeriodMember;
use App\Models\Posyandu;
use App\Models\School;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BeneficiaryPeriodSnapshotService
{
    public function importCurrentMaster(
        BeneficiaryPeriod $period,
        User $actor,
        bool $replaceExisting = false,
    ): array {
        $this->assertEditable($period);

        $beneficiaries = Beneficiary::query()
            ->with(['beneficiaryable', 'category'])
            ->where('sppg_unit_id', $period->sppg_unit_id)
            ->where('is_active', true)
            ->orderBy('beneficiaryable_type')
            ->orderBy('beneficiaryable_id')
            ->orderBy('name')
            ->get();

        if ($beneficiaries->isEmpty()) {
            throw new DomainException('Master Penerima aktif belum tersedia pada Unit SPPG ini.');
        }

        return DB::transaction(function () use ($period, $actor, $replaceExisting, $beneficiaries): array {
            if ($replaceExisting) {
                $period->destinations()->delete();
            }

            $destinationCount = 0;
            $memberCount = 0;

            foreach ($beneficiaries->groupBy(fn (Beneficiary $item): string => implode('|', [
                $item->beneficiaryable_type,
                $item->beneficiaryable_id,
            ])) as $group) {
                /** @var Beneficiary $first */
                $first = $group->first();
                $institution = $first->beneficiaryable;

                if (! $institution instanceof Model) {
                    continue;
                }

                $destination = $this->upsertDestinationFromInstitution($period, $institution);
                $destinationCount++;

                foreach ($group as $beneficiary) {
                    $this->upsertMemberFromBeneficiary($period, $destination, $beneficiary);
                    $memberCount++;
                }
            }

            $this->recalculate($period);
            $this->history($period, $actor, 'import_current_master', null, null, null, [
                'destinations' => $destinationCount,
                'members' => $memberCount,
                'replace_existing' => $replaceExisting,
            ]);

            return compact('destinationCount', 'memberCount');
        });
    }

    public function copyPeriod(
        BeneficiaryPeriod $source,
        BeneficiaryPeriod $target,
        User $actor,
    ): array {
        $this->assertEditable($target);

        if ((int) $source->sppg_unit_id !== (int) $target->sppg_unit_id) {
            throw new DomainException('Periode sumber dan tujuan harus berada pada Unit SPPG yang sama.');
        }

        $source->load(['destinations.members']);

        if ($source->destinations->isEmpty()) {
            throw new DomainException('Periode sumber belum memiliki snapshot penerima.');
        }

        return DB::transaction(function () use ($source, $target, $actor): array {
            $target->destinations()->delete();
            $destinationCount = 0;
            $memberCount = 0;

            foreach ($source->destinations as $sourceDestination) {
                $destination = BeneficiaryPeriodDestination::withoutEvents(fn () => $target->destinations()->create([
                    'destination_key' => $sourceDestination->destination_key,
                    'destination_type' => $sourceDestination->destination_type,
                    'destination_id' => $sourceDestination->destination_id,
                    'destination_code_snapshot' => $sourceDestination->destination_code_snapshot,
                    'destination_name_snapshot' => $sourceDestination->destination_name_snapshot,
                    'address_snapshot' => $sourceDestination->address_snapshot,
                    'contact_name_snapshot' => $sourceDestination->contact_name_snapshot,
                    'contact_phone_snapshot' => $sourceDestination->contact_phone_snapshot,
                    'latitude_snapshot' => $sourceDestination->latitude_snapshot,
                    'longitude_snapshot' => $sourceDestination->longitude_snapshot,
                    'preferred_delivery_time' => $sourceDestination->preferred_delivery_time,
                    'sort_order' => $sourceDestination->sort_order,
                    'is_active' => $sourceDestination->is_active,
                    'notes' => $sourceDestination->notes,
                ]));
                $destinationCount++;

                foreach ($sourceDestination->members as $sourceMember) {
                    $data = $sourceMember->only([
                        'source_beneficiary_id', 'beneficiary_category_id', 'member_code', 'identity_number',
                        'name', 'birth_date', 'gender', 'parent_name', 'recipient_position', 'education_level',
                        'class_group', 'beneficiary_category_code_snapshot', 'beneficiary_category_name_snapshot',
                        'portion_category', 'menu_audience', 'address', 'allergy_notes', 'special_needs', 'notes',
                        'is_active',
                    ]);
                    $data['beneficiary_period_id'] = $target->getKey();
                    $data['beneficiary_period_destination_id'] = $destination->getKey();
                    BeneficiaryPeriodMember::withoutEvents(fn () => BeneficiaryPeriodMember::query()->create($data));
                    $memberCount++;
                }
            }

            $target->forceFill(['source_period_id' => $source->getKey()])->save();
            $this->recalculate($target);
            $this->history($target, $actor, 'copy_period', null, null, null, [
                'source_period_id' => $source->getKey(),
                'destinations' => $destinationCount,
                'members' => $memberCount,
            ]);

            return compact('destinationCount', 'memberCount');
        });
    }

    public function promoteClasses(BeneficiaryPeriod $period, User $actor): array
    {
        $this->assertEditable($period);

        $categories = BeneficiaryCategory::query()
            ->where('sppg_unit_id', $period->sppg_unit_id)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (BeneficiaryCategory $category): string => strtoupper($category->code));

        $updated = 0;
        $graduated = 0;
        $skipped = 0;

        DB::transaction(function () use ($period, $actor, $categories, &$updated, &$graduated, &$skipped): void {
            $period->members()->where('is_active', true)->orderBy('id')->chunkById(250, function ($members) use ($categories, &$updated, &$graduated, &$skipped): void {
                foreach ($members as $member) {
                    $grade = $this->parseGrade($member->class_group);
                    $level = strtoupper((string) $member->education_level);

                    if ($grade === null || ! in_array($level, ['SD', 'SMP', 'SMA'], true)) {
                        $skipped++;
                        continue;
                    }

                    $terminal = match ($level) {
                        'SD' => $grade >= 6,
                        'SMP' => $grade >= (in_array($grade, [7, 8, 9], true) ? 9 : 3),
                        'SMA' => $grade >= (in_array($grade, [10, 11, 12], true) ? 12 : 3),
                        default => false,
                    };

                    if ($terminal) {
                        BeneficiaryPeriodMember::withoutEvents(fn () => $member->update([
                            'is_active' => false,
                            'notes' => trim(($member->notes ? $member->notes."\n" : '').'Dinonaktifkan otomatis karena kelas akhir pada pergantian periode.'),
                        ]));
                        $graduated++;
                        continue;
                    }

                    $newGrade = $grade + 1;
                    $updates = ['class_group' => (string) $newGrade];
                    $categoryCode = $this->categoryCodeFor($level, $newGrade);
                    $category = $categoryCode ? $categories->get($categoryCode) : null;

                    if ($category) {
                        $updates += [
                            'beneficiary_category_id' => $category->getKey(),
                            'beneficiary_category_code_snapshot' => $category->code,
                            'beneficiary_category_name_snapshot' => $category->name,
                            'portion_category' => $category->portion_size,
                            'menu_audience' => $category->menu_audience,
                        ];
                    }

                    BeneficiaryPeriodMember::withoutEvents(fn () => $member->update($updates));
                    $updated++;
                }
            });

            $this->recalculate($period);
            $this->history($period, $actor, 'promote_classes', null, null, null, compact('updated', 'graduated', 'skipped'));
        });

        return compact('updated', 'graduated', 'skipped');
    }

    public function recalculate(BeneficiaryPeriod $period): void
    {
        $period->forceFill([
            'destination_count' => $period->destinations()->where('is_active', true)->count(),
            'total_members' => $period->members()->count(),
            'active_members' => $period->members()->where('is_active', true)->count(),
        ])->saveQuietly();
    }

    public function assertEditable(BeneficiaryPeriod $period): void
    {
        if (! $period->isEditable()) {
            throw new DomainException('Master periode sudah diajukan atau dikunci dan tidak dapat diubah.');
        }
    }

    private function upsertDestinationFromInstitution(BeneficiaryPeriod $period, Model $institution): BeneficiaryPeriodDestination
    {
        $type = match (true) {
            $institution instanceof School => 'school',
            $institution instanceof Posyandu => 'posyandu',
            default => 'other',
        };
        $key = $type.':'.$institution->getKey();

        return BeneficiaryPeriodDestination::withoutEvents(fn () => $period->destinations()->updateOrCreate(
            ['destination_key' => $key],
            [
                'destination_type' => $type,
                'destination_id' => $institution->getKey(),
                'destination_code_snapshot' => $institution->code ?? null,
                'destination_name_snapshot' => $institution->name ?? 'Tanpa Nama',
                'address_snapshot' => $institution->address ?? null,
                'contact_name_snapshot' => $institution->pic_name ?? $institution->contact_person ?? null,
                'contact_phone_snapshot' => $institution->pic_phone ?? $institution->phone ?? $institution->contact_phone ?? null,
                'latitude_snapshot' => $institution->latitude ?? null,
                'longitude_snapshot' => $institution->longitude ?? null,
                'preferred_delivery_time' => $institution->receiving_time ?? $institution->preferred_delivery_time ?? null,
                'is_active' => true,
            ]
        ));
    }

    private function upsertMemberFromBeneficiary(
        BeneficiaryPeriod $period,
        BeneficiaryPeriodDestination $destination,
        Beneficiary $beneficiary,
    ): BeneficiaryPeriodMember {
        $category = $beneficiary->category;
        $identity = filled($beneficiary->external_id) ? (string) $beneficiary->external_id : null;

        $lookup = $beneficiary->getKey()
            ? ['source_beneficiary_id' => $beneficiary->getKey()]
            : ['identity_number' => $identity, 'name' => $beneficiary->name];

        return BeneficiaryPeriodMember::withoutEvents(fn () => $period->members()->updateOrCreate($lookup, [
            'beneficiary_period_destination_id' => $destination->getKey(),
            'beneficiary_category_id' => $beneficiary->beneficiary_category_id,
            'member_code' => $beneficiary->code,
            'identity_number' => $identity,
            'name' => $beneficiary->name,
            'birth_date' => $beneficiary->birth_date,
            'gender' => $beneficiary->gender,
            'parent_name' => $beneficiary->parent_name,
            'recipient_position' => (string) ($beneficiary->recipient_position ?: '1'),
            'education_level' => $category?->education_level,
            'class_group' => $beneficiary->group_name,
            'beneficiary_category_code_snapshot' => $category?->code,
            'beneficiary_category_name_snapshot' => $category?->name,
            'portion_category' => $category?->portion_size,
            'menu_audience' => $category?->menu_audience,
            'address' => $beneficiary->address,
            'allergy_notes' => $beneficiary->allergy_notes,
            'special_needs' => $beneficiary->special_needs,
            'notes' => $beneficiary->notes,
            'is_active' => $beneficiary->is_active,
        ]));
    }

    private function parseGrade(?string $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        $normalized = strtoupper(trim((string) $value));
        $normalized = preg_replace('/^(KELAS|CLASS|TINGKAT)\s*/', '', $normalized) ?: $normalized;

        if (preg_match('/\d+/', $normalized, $match)) {
            return (int) $match[0];
        }

        return match ($normalized) {
            'I' => 1, 'II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6,
            'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10, 'XI' => 11, 'XII' => 12,
            default => null,
        };
    }

    private function categoryCodeFor(string $level, int $grade): ?string
    {
        return match ($level) {
            'SD' => $grade <= 3 ? 'SD_1_3' : 'SD_4_6',
            'SMP' => 'SMP',
            'SMA' => 'SMA',
            default => null,
        };
    }

    private function history(
        BeneficiaryPeriod $period,
        User $actor,
        string $action,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $notes = null,
        array $metadata = [],
    ): void {
        $period->histories()->create([
            'user_id' => $actor->getKey(),
            'action' => $action,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'notes' => $notes,
            'metadata' => $metadata ?: null,
        ]);
    }
}
