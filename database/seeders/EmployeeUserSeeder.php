<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\SppgUnit;
use App\Models\User;
use App\Services\UserUnitAccessService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class EmployeeUserSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $unit = SppgUnit::query()
            ->where('code', 'SPPG-001')
            ->first();

        if (! $unit) {
            throw new RuntimeException(
                'Unit SPPG-001 belum tersedia. Jalankan AccessControlSeeder terlebih dahulu.'
            );
        }

        $employees = $this->employees();
        $requiredRoleNames = collect($employees)
            ->pluck('role')
            ->unique()
            ->values();

        $roles = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $requiredRoleNames)
            ->get()
            ->keyBy('name');

        $missingRoles = $requiredRoleNames
            ->reject(static fn (string $roleName): bool => $roles->has($roleName))
            ->values();

        if ($missingRoles->isNotEmpty()) {
            throw new RuntimeException(
                'Role berikut belum tersedia: '.$missingRoles->implode(', ').
                '. Jalankan AccessControlSeeder terlebih dahulu.'
            );
        }

        $accessService = app(UserUnitAccessService::class);

        foreach ($employees as $employee) {
            $email = $this->makeEmail(
                name: $employee['name'],
                cardUid: $employee['card_uid'],
            );

            $user = User::query()->updateOrCreate(
                ['employee_number' => $employee['card_uid']],
                [
                    'name' => $employee['name'],
                    'email' => $email,
                    'phone' => null,
                    'password' => Hash::make('password123'),
                    'is_active' => $employee['status'] === 'Aktif',
                    'is_super_admin' => false,
                ]
            );

            $user->forceFill([
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            /** @var Role $role */
            $role = $roles->get($employee['role']);

            $accessService->sync(
                user: $user,
                unit: $unit,
                roleId: (int) $role->getKey(),
                isMembershipActive: $employee['status'] === 'Aktif',
            );

            $this->command?->line(
                sprintf(
                    '%s | %s | %s | %s',
                    $employee['card_uid'],
                    $employee['name'],
                    $employee['role'],
                    $email,
                )
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info(
            count($employees).' akun pegawai berhasil dibuat/diperbarui. Password: password123'
        );
    }

    /**
     * Email dummy dibuat stabil agar tidak berubah setiap seeder dijalankan ulang.
     */
    private function makeEmail(string $name, string $cardUid): string
    {
        $namePart = Str::slug($name, '.');
        $uidPart = Str::lower(substr($cardUid, -4));

        return "{$namePart}.{$uidPart}@sppg.test";
    }

    /**
     * @return array<int, array{
     *     card_uid: string,
     *     name: string,
     *     role: string,
     *     status: string
     * }>
     */
    private function employees(): array
    {
        return [
            ['card_uid' => '903A296F', 'name' => 'Juwadi', 'role' => UserRole::KepalaDivisiKebersihan->value, 'status' => 'Aktif'],
            ['card_uid' => '8E82286F', 'name' => 'Topo Rudihartono', 'role' => UserRole::PetugasKebersihan->value, 'status' => 'Aktif'],
            ['card_uid' => '6BB6296F', 'name' => 'Alan Ardianto Kurniawan', 'role' => UserRole::PetugasPengolahan->value, 'status' => 'Aktif'],

            ['card_uid' => '50C9296F', 'name' => 'Soleh Joko Prihatin', 'role' => UserRole::KepalaDivisiPersiapan->value, 'status' => 'Aktif'],
            ['card_uid' => 'E02D296F', 'name' => 'Veronica Dian Puji Lestari', 'role' => UserRole::PetugasPersiapan->value, 'status' => 'Aktif'],
            ['card_uid' => 'F9CB296F', 'name' => 'Sukeni', 'role' => UserRole::PetugasPersiapan->value, 'status' => 'Aktif'],
            ['card_uid' => 'B0A1296F', 'name' => 'Inatri Suparmi', 'role' => UserRole::PetugasPersiapan->value, 'status' => 'Aktif'],
            ['card_uid' => '29A5296F', 'name' => 'Yuniarti', 'role' => UserRole::PetugasPersiapan->value, 'status' => 'Aktif'],
            ['card_uid' => '3109296F', 'name' => 'Destri Djoko Djatmiko', 'role' => UserRole::PetugasPersiapan->value, 'status' => 'Aktif'],
            ['card_uid' => 'D9BA296F', 'name' => 'Caru Felliy Heteru', 'role' => UserRole::PetugasPersiapan->value, 'status' => 'Aktif'],
            ['card_uid' => '2964296F', 'name' => 'Arfian', 'role' => UserRole::PetugasPersiapan->value, 'status' => 'Aktif'],

            ['card_uid' => 'B28E7D2F', 'name' => 'Septiana Citra Wulaningrum', 'role' => UserRole::KepalaDivisiPengolahan->value, 'status' => 'Aktif'],
            ['card_uid' => '1E0C7D2F', 'name' => 'Addin Sidik Purnomo', 'role' => UserRole::PetugasPengolahan->value, 'status' => 'Aktif'],
            ['card_uid' => '7AAF7A2F', 'name' => 'Muhammad Thoriq Azis', 'role' => UserRole::PetugasPengolahan->value, 'status' => 'Aktif'],
            ['card_uid' => '5414762F', 'name' => 'Jaka Mulyana', 'role' => UserRole::PetugasPengolahan->value, 'status' => 'Aktif'],
            ['card_uid' => 'CB8C742F', 'name' => 'Evi Andriyani Cahyawati', 'role' => UserRole::PetugasPengolahan->value, 'status' => 'Aktif'],
            ['card_uid' => 'DADB7D2F', 'name' => 'Natan Irsa Aliffian', 'role' => UserRole::PetugasPengolahan->value, 'status' => 'Aktif'],
            ['card_uid' => '427C7E2F', 'name' => 'Warsiyah', 'role' => UserRole::PetugasPengolahan->value, 'status' => 'Aktif'],
            ['card_uid' => '6ABC752F', 'name' => 'Sulistiyani', 'role' => UserRole::PetugasPengolahan->value, 'status' => 'Aktif'],

            ['card_uid' => '8398286F', 'name' => 'Yani Sofiatiningrum', 'role' => UserRole::KepalaDivisiPemorsian->value, 'status' => 'Aktif'],
            ['card_uid' => '07EF286F', 'name' => 'Setiyo Budi Nugroho', 'role' => UserRole::PetugasPemorsian->value, 'status' => 'Aktif'],
            ['card_uid' => 'A57C296F', 'name' => 'Muhammad Wakhid Firmansyah', 'role' => UserRole::PetugasPemorsian->value, 'status' => 'Aktif'],
            ['card_uid' => 'EC87296F', 'name' => 'Danang Ari Santoso', 'role' => UserRole::PetugasPemorsian->value, 'status' => 'Aktif'],
            ['card_uid' => '74CC286F', 'name' => 'Hendrichus Feryindarta', 'role' => UserRole::PetugasPemorsian->value, 'status' => 'Aktif'],
            ['card_uid' => '449B391E', 'name' => 'Rizal Dwi Nugroho', 'role' => UserRole::PetugasPemorsian->value, 'status' => 'Aktif'],
            ['card_uid' => '2CA83B1E', 'name' => 'Sekti Fikriyah', 'role' => UserRole::PetugasPemorsian->value, 'status' => 'Aktif'],
            ['card_uid' => '96FA7D2F', 'name' => 'Cahyo Wibowo', 'role' => UserRole::PetugasPemorsian->value, 'status' => 'Aktif'],
            ['card_uid' => 'F6883B1E', 'name' => 'Ismail', 'role' => UserRole::PetugasPemorsian->value, 'status' => 'Aktif'],
            ['card_uid' => '42EF391E', 'name' => 'Diyamto', 'role' => UserRole::PetugasPemorsian->value, 'status' => 'Aktif'],
            ['card_uid' => 'E56262BC', 'name' => 'Nabila Septia Aditya', 'role' => UserRole::PetugasPemorsian->value, 'status' => 'Aktif'],

            ['card_uid' => '2C67286F', 'name' => 'Rendi Irawan', 'role' => UserRole::KepalaDivisiDistribusi->value, 'status' => 'Aktif'],
            ['card_uid' => '2977296F', 'name' => 'Mulyadi', 'role' => UserRole::PetugasDistribusi->value, 'status' => 'Aktif'],
            ['card_uid' => 'EDF0286F', 'name' => 'Tono Purwiyanto', 'role' => UserRole::PetugasDistribusi->value, 'status' => 'Aktif'],
            ['card_uid' => '7A88296F', 'name' => 'Abdul Jalil', 'role' => UserRole::PetugasDistribusi->value, 'status' => 'Aktif'],
            ['card_uid' => 'BB96296F', 'name' => 'Erva Yudianto', 'role' => UserRole::PetugasDistribusi->value, 'status' => 'Aktif'],
            ['card_uid' => '0B70BEA9', 'name' => 'Muhammad Aziz N.', 'role' => UserRole::PetugasDistribusi->value, 'status' => 'Aktif'],

            ['card_uid' => 'D993286F', 'name' => 'Avi Zuhana', 'role' => UserRole::KepalaDivisiPencucian->value, 'status' => 'Aktif'],
            ['card_uid' => 'C1FC286F', 'name' => 'Suryani', 'role' => UserRole::PetugasPencucian->value, 'status' => 'Aktif'],
            ['card_uid' => '2226296F', 'name' => 'Tia Savitri', 'role' => UserRole::PetugasPencucian->value, 'status' => 'Aktif'],
            ['card_uid' => 'DC6B296F', 'name' => 'Anita Rahmawati', 'role' => UserRole::PetugasPencucian->value, 'status' => 'Aktif'],
            ['card_uid' => '4AEB286F', 'name' => 'Novi Nur Azizah', 'role' => UserRole::PetugasPencucian->value, 'status' => 'Aktif'],
            ['card_uid' => '3BC6296F', 'name' => 'Priyanti Wulandari', 'role' => UserRole::PetugasPencucian->value, 'status' => 'Aktif'],
            ['card_uid' => '5578762F', 'name' => 'Verayuliana', 'role' => UserRole::PetugasPencucian->value, 'status' => 'Aktif'],
            ['card_uid' => '3E7A7D2F', 'name' => 'Sukini', 'role' => UserRole::PetugasPencucian->value, 'status' => 'Aktif'],
            ['card_uid' => 'FC6F296F', 'name' => 'Endri Aryanto', 'role' => UserRole::PetugasPencucian->value, 'status' => 'Aktif'],
            ['card_uid' => '52BD296F', 'name' => 'Fajar Eiy. K', 'role' => UserRole::PetugasPencucian->value, 'status' => 'Aktif'],
            ['card_uid' => 'CDF9286F', 'name' => 'Alfian Nur Iksan', 'role' => UserRole::PetugasPencucian->value, 'status' => 'Aktif'],
            ['card_uid' => 'B021296F', 'name' => 'Arief Nur Rosyid', 'role' => UserRole::PetugasPencucian->value, 'status' => 'Aktif'],

            ['card_uid' => '91D5286F', 'name' => 'Muhammad Putra Khaerudin', 'role' => UserRole::StafGudang->value, 'status' => 'Aktif'],
            ['card_uid' => 'E7C6296F', 'name' => 'Alvin Fauzi Hakim', 'role' => UserRole::PengawasKeuangan->value, 'status' => 'Aktif'],
            ['card_uid' => '7E74296F', 'name' => 'Masna Luthfia Rahma', 'role' => UserRole::AhliGizi->value, 'status' => 'Aktif'],
            ['card_uid' => 'A9CE51A3', 'name' => 'Sujaryanto', 'role' => UserRole::Satpam->value, 'status' => 'Aktif'],
            ['card_uid' => 'F955F7A3', 'name' => 'Ferry Subekti', 'role' => UserRole::Satpam->value, 'status' => 'Aktif'],
            ['card_uid' => '0DF23S42', 'name' => 'Ahmad Makarim Pramudita', 'role' => UserRole::KepalaSppg->value, 'status' => 'Aktif'],
        ];
    }
}
